"""
کلاینت امن ارتباط با افزونهٔ وردپرس — httpx async + retry + logging
"""
import asyncio
import ipaddress
import logging
from typing import Optional
from urllib.parse import urlsplit

import httpx

from .config import AHANGBAN_API_BASE, AHANGBAN_API_TOKEN, CACHE_TTL

log = logging.getLogger("ahangban.api")

class AhangBanAPI:
    @staticmethod
    def _validate_base(base: str) -> str:
        try:
            parsed = urlsplit((base or "").strip())
            hostname = (parsed.hostname or "").lower().rstrip(".")
            # Force validation of malformed or out-of-range port syntax.
            parsed.port
        except ValueError as exc:
            raise ValueError("AHANGBAN_API_BASE یک نشانی HTTPS معتبر نیست") from exc

        if (
            parsed.scheme != "https"
            or not hostname
            or parsed.username
            or parsed.password
            or parsed.query
            or parsed.fragment
            or hostname == "localhost"
            or hostname.endswith((".localhost", ".local", ".internal", ".test", ".invalid"))
        ):
            raise ValueError("AHANGBAN_API_BASE باید HTTPS عمومی و بدون credential باشد")
        try:
            address = ipaddress.ip_address(hostname)
        except ValueError:
            address = None
        if address is not None and not address.is_global:
            raise ValueError("AHANGBAN_API_BASE نباید به IP خصوصی/رزروشده اشاره کند")
        return base.strip().rstrip("/")

    def __init__(self, base: str = AHANGBAN_API_BASE, token: str = AHANGBAN_API_TOKEN, timeout: int = 15):
        self.base = self._validate_base(base)
        self.token = token
        self.timeout = timeout
        self._client: Optional[httpx.AsyncClient] = None
        self._cache: dict[str, tuple[float, dict]] = {}  # key → (expiry, data)

    def _headers(self) -> dict:
        return {
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json",
            "User-Agent": "AhangBanBot/1.0 (+https://ahangban.ir)",
        }

    def _get_client(self) -> httpx.AsyncClient:
        if not self._client or self._client.is_closed:
            self._client = httpx.AsyncClient(
                timeout=self.timeout,
                follow_redirects=False,
                limits=httpx.Limits(max_connections=30, max_keepalive_connections=10),
            )
        return self._client

    async def close(self):
        if self._client and not self._client.is_closed:
            await self._client.aclose()

    def _cache_key(self, path: str, params: dict) -> str:
        return f"{path}:{sorted(params.items())}"

    def _cache_get(self, key: str):
        import time
        if key in self._cache:
            exp, data = self._cache[key]
            if time.time() < exp:
                return data
            del self._cache[key]
        return None

    def _cache_set(self, key: str, data: dict, ttl: int = CACHE_TTL):
        import time
        self._cache[key] = (time.time() + ttl, data)
        # Simple LRU: keep max 200 entries
        if len(self._cache) > 200:
            oldest = min(self._cache, key=lambda k: self._cache[k][0])
            del self._cache[oldest]

    async def _request(
        self,
        method: str,
        path: str,
        params: dict = None,
        use_cache: bool = True,
        json_body: dict = None,
        max_attempts: int = 3,
    ) -> dict:
        url = f"{self.base}/{path.lstrip('/')}"
        params = params or {}
        max_attempts = max(1, min(int(max_attempts), 3))
        cache_key = self._cache_key(path, params)
        if use_cache and method == "GET":
            cached = self._cache_get(cache_key)
            if cached is not None:
                log.debug("cache hit %s", cache_key)
                return cached

        client = self._get_client()
        # Retry with backoff for transient errors. Non-idempotent calls can opt out.
        last_exc = None
        last_status = 0
        for attempt in range(max_attempts):
            try:
                resp = await client.request(
                    method,
                    url,
                    params=params,
                    json=json_body,
                    headers=self._headers(),
                )
                if resp.status_code == 429:
                    # Honor Retry-After, but keep the interaction responsive and bounded.
                    last_status = 429
                    try:
                        wait = float(resp.headers.get("Retry-After", "2"))
                    except (TypeError, ValueError):
                        wait = 2.0
                    wait = max(0.0, min(wait, 3.0))
                    log.warning("API rate limited; retry %d/%d after %.1fs", attempt + 1, max_attempts, wait)
                    if attempt < max_attempts - 1:
                        await asyncio.sleep(wait)
                    continue
                if 300 <= resp.status_code < 400:
                    raise APIError(resp.status_code, "آدرس API سایت تغییر مسیر داد؛ نشانی پایه را بررسی کنید.")
                resp.raise_for_status()
                data = resp.json()
                if use_cache and method == "GET":
                    self._cache_set(cache_key, data)
                return data
            except APIError:
                raise
            except httpx.HTTPStatusError as e:
                last_status = e.response.status_code
                # Don't retry 4xx (except 429 already handled)
                if 400 <= e.response.status_code < 500:
                    body = ""
                    try:
                        j = e.response.json()
                        body = j.get("message") or j.get("code") or str(j)
                    except Exception:
                        body = e.response.text[:500]
                    log.error("API %s %s → %s %s", method, path, e.response.status_code, body)
                    raise APIError(e.response.status_code, body) from e
                last_exc = e
                log.warning("transient API server response %s on attempt %d", e.response.status_code, attempt + 1)
                if attempt < max_attempts - 1:
                    await asyncio.sleep(0.5 * (attempt + 1))
            except (httpx.ConnectError, httpx.ReadTimeout, httpx.TimeoutException) as e:
                last_exc = e
                last_status = 0
                log.warning("API network error on attempt %d (%s)", attempt + 1, type(e).__name__)
                if attempt < max_attempts - 1:
                    await asyncio.sleep(0.5 * (attempt + 1))
            except ValueError as e:
                last_exc = e
                last_status = 502
                log.warning("API returned invalid JSON for %s", path)
                if attempt < max_attempts - 1:
                    await asyncio.sleep(0.5 * (attempt + 1))
        if last_status == 429:
            raise APIError(429, "سایت در حال حاضر درخواست‌های زیادی دریافت می‌کند؛ چند لحظهٔ دیگر دوباره تلاش کنید.") from last_exc
        if last_status >= 500:
            raise APIError(last_status, "پاسخ سایت موقتاً در دسترس نیست؛ دوباره تلاش کنید.") from last_exc
        raise APIError(0, "ارتباط با سایت برقرار نشد؛ اتصال HTTPS را بررسی و دوباره تلاش کنید.") from last_exc

    # ---- Public methods ----

    async def health(self) -> dict:
        # Health is unauthenticated but we still send token (harmless)
        return await self._request("GET", "health", use_cache=False)

    async def search(self, q: str, type_: str = "", page: int = 1, per_page: int = 5) -> dict:
        return await self._request("GET", "search", {"q": q, "type": type_, "page": page, "per_page": per_page})

    async def song(self, song_id: int) -> dict:
        return await self._request("GET", f"song/{song_id}")

    async def artists(self, q: str = "", letter: str = "", sort: str = "name", page: int = 1, per_page: int = 10) -> dict:
        params = {"page": page, "per_page": per_page, "sort": sort}
        if q: params["q"] = q
        if letter: params["letter"] = letter
        return await self._request("GET", "artists", params)

    async def artist(self, identifier: str, page: int = 1, per_page: int = 5, sort: str = "new") -> dict:
        return await self._request("GET", f"artist/{identifier}", {"page": page, "per_page": per_page, "sort": sort})

    async def latest(self, page: int = 1, per_page: int = 5) -> dict:
        return await self._request("GET", "latest", {"page": page, "per_page": per_page})

    async def popular(self, page: int = 1, per_page: int = 5) -> dict:
        return await self._request("GET", "popular", {"page": page, "per_page": per_page})

    async def cache_clear(self) -> dict:
        return await self._request("POST", "cache/clear", use_cache=False)

    async def publish_channel_post(self, post_id: int) -> dict:
        """Ask WordPress to publish one selected post using its configured channel and bot token.

        This is intentionally a single attempt: Telegram may accept the send while the response
        is lost, so retrying the request could publish the same post twice.
        """
        post_id = int(post_id)
        if post_id <= 0:
            raise ValueError("شناسهٔ پست باید عددی مثبت باشد.")
        return await self._request(
            "POST",
            "channel/publish",
            use_cache=False,
            json_body={"post_id": post_id},
            max_attempts=1,
        )

    def invalidate(self, pattern: str = ""):
        if not pattern:
            self._cache.clear()
        else:
            for k in list(self._cache.keys()):
                if pattern in k:
                    del self._cache[k]


class APIError(Exception):
    def __init__(self, status: int, message: str):
        self.status = status
        self.message = message
        super().__init__(f"[{status}] {message}")

# Global singleton
api = AhangBanAPI()
