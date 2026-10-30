"""کلاینت امن ارتباط با API افزونهٔ وردپرس و حساب کاربری قالب Taranehyar."""
import asyncio
import ipaddress
import logging
import re
from typing import Optional
from urllib.parse import urlsplit, urlunsplit

import httpx

from .config import AHANGBAN_API_BASE, AHANGBAN_API_TOKEN, CACHE_TTL, TARA_API_BASE

log = logging.getLogger("ahangban.api")


class AhangBanAPI:
    @staticmethod
    def _validate_base(base: str) -> str:
        try:
            parsed = urlsplit((base or "").strip())
            hostname = (parsed.hostname or "").lower().rstrip(".")
            parsed.port  # Force validation of malformed or out-of-range port syntax.
        except ValueError as exc:
            raise ValueError("نشانی API باید HTTPS عمومی و معتبر باشد") from exc

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
            raise ValueError("نشانی API باید HTTPS عمومی و بدون credential باشد")
        try:
            address = ipaddress.ip_address(hostname)
        except ValueError:
            address = None
        if address is not None and not address.is_global:
            raise ValueError("نشانی API نباید به IP خصوصی/رزروشده اشاره کند")
        return base.strip().rstrip("/")

    @staticmethod
    def _derive_account_base(base: str) -> str:
        parsed = urlsplit(base)
        path = parsed.path.rstrip("/")
        if path.endswith("/wp-json/ahangban/v1"):
            path = path[: -len("ahangban/v1")] + "tara/v1"
        elif path.endswith("/wp-json/tara/v1"):
            pass
        elif path.endswith("/wp-json"):
            path += "/tara/v1"
        else:
            path = "/wp-json/tara/v1"
        return urlunsplit((parsed.scheme, parsed.netloc, path, "", ""))

    def __init__(
        self,
        base: str = AHANGBAN_API_BASE,
        token: str = AHANGBAN_API_TOKEN,
        timeout: int = 15,
        account_base: str | None = None,
    ):
        self.base = self._validate_base(base)
        self.account_base = self._validate_base(account_base or self._derive_account_base(self.base))
        self.token = token
        self.timeout = timeout
        self._client: Optional[httpx.AsyncClient] = None
        self._cache: dict[str, tuple[float, dict]] = {}  # key → (expiry, data)

    def _headers(self, *, authenticated: bool = True) -> dict:
        headers = {
            "Accept": "application/json",
            "User-Agent": "AhangBanBot/1.0",
        }
        if authenticated:
            headers["Authorization"] = f"Bearer {self.token}"
        return headers

    def _redact_secret(self, value) -> str:
        text = str(value or "")
        if self.token:
            text = text.replace(str(self.token), "[redacted]")
        return text

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

    def _cache_key(self, path: str, params: dict, base: str | None = None) -> str:
        request_base = self.base if base is None else base
        return f"{request_base.rstrip('/')}/{path.lstrip('/')}:{sorted(params.items())}"

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
        # Simple LRU: keep max 200 entries.
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
        request_base: str | None = None,
        authenticated: bool = True,
    ) -> dict:
        base = self.base if request_base is None else request_base
        url = f"{base.rstrip('/')}/{path.lstrip('/')}"
        params = params or {}
        max_attempts = max(1, min(int(max_attempts), 3))
        cache_key = self._cache_key(path, params, base)
        if use_cache and method == "GET":
            cached = self._cache_get(cache_key)
            if cached is not None:
                log.debug("API cache hit for %s", path)
                return cached

        client = self._get_client()
        # Retry transient GETs; mutating calls can opt out because a lost response is ambiguous.
        last_exc = None
        last_status = 0
        for attempt in range(max_attempts):
            try:
                resp = await client.request(
                    method,
                    url,
                    params=params,
                    json=json_body,
                    headers=self._headers(authenticated=authenticated),
                )
                if resp.status_code == 429:
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
            except httpx.HTTPStatusError as exc:
                last_status = exc.response.status_code
                # Don't retry 4xx (except 429 handled above).
                if 400 <= exc.response.status_code < 500:
                    payload = {}
                    try:
                        parsed_body = exc.response.json()
                        if isinstance(parsed_body, dict):
                            payload = parsed_body
                    except Exception:
                        pass
                    code = self._redact_secret(payload.get("code") or "")[:120]
                    body = self._redact_secret(payload.get("message") or code or exc.response.text[:500])[:500]
                    if exc.response.status_code in (401, 403, 503):
                        log.error(
                            "WordPress API authentication/configuration rejected %s %s (HTTP %s, code=%s)",
                            method,
                            path,
                            exc.response.status_code,
                            code or "unknown",
                        )
                    else:
                        log.error("API %s %s → %s %s", method, path, exc.response.status_code, body)
                    raise APIError(exc.response.status_code, body, code=code) from exc
                last_exc = exc
                log.warning("transient API server response %s on attempt %d", exc.response.status_code, attempt + 1)
                if attempt < max_attempts - 1:
                    await asyncio.sleep(0.5 * (attempt + 1))
            except (httpx.ConnectError, httpx.ReadTimeout, httpx.TimeoutException) as exc:
                last_exc = exc
                last_status = 0
                log.warning("API network error on attempt %d (%s)", attempt + 1, type(exc).__name__)
                if attempt < max_attempts - 1:
                    await asyncio.sleep(0.5 * (attempt + 1))
            except ValueError as exc:
                last_exc = exc
                last_status = 502
                log.warning("API returned invalid JSON for %s", path)
                if attempt < max_attempts - 1:
                    await asyncio.sleep(0.5 * (attempt + 1))
        if last_status == 429:
            raise APIError(429, "سایت در حال حاضر درخواست‌های زیادی دریافت می‌کند؛ چند لحظهٔ دیگر دوباره تلاش کنید.") from last_exc
        if last_status >= 500:
            raise APIError(last_status, "پاسخ سایت موقتاً در دسترس نیست؛ دوباره تلاش کنید.") from last_exc
        raise APIError(0, "ارتباط با سایت برقرار نشد؛ اتصال HTTPS را بررسی و دوباره تلاش کنید.") from last_exc

    @staticmethod
    def _telegram_id(value: int) -> int:
        if isinstance(value, bool):
            raise ValueError("شناسهٔ تلگرام باید عدد صحیح مثبت باشد.")
        if isinstance(value, int):
            telegram_id = value
        elif isinstance(value, str) and re.fullmatch(r"[0-9]+", value.strip()):
            telegram_id = int(value.strip())
        else:
            raise ValueError("شناسهٔ تلگرام باید عدد صحیح مثبت باشد.")
        if telegram_id <= 0:
            raise ValueError("شناسهٔ تلگرام باید عدد صحیح مثبت باشد.")
        return telegram_id

    async def _account_post(self, path: str, telegram_id: int, body: dict) -> dict:
        telegram_id = self._telegram_id(telegram_id)
        try:
            return await self._request(
                "POST",
                path,
                use_cache=False,
                json_body={"telegram_id": telegram_id, **body},
                max_attempts=1,
                request_base=self.account_base,
            )
        finally:
            # Even a dropped/invalid response can follow a committed WordPress mutation.
            self.invalidate_account(telegram_id)

    # ---- Public music API methods (AhangBan Connector) ----

    async def health(self) -> dict:
        """Check the public health route without sending the API secret."""
        try:
            data = await self._request(
                "GET",
                "health",
                use_cache=False,
                max_attempts=1,
                request_base=self.account_base,
                authenticated=False,
            )
        except APIError as exc:
            # Older installations may expose only the connector namespace health route.
            if exc.status != 404:
                raise
            data = await self._request(
                "GET",
                "health",
                use_cache=False,
                max_attempts=1,
                request_base=self.base,
                authenticated=False,
            )
        if isinstance(data, dict) and (data.get("ok") is True or data.get("status") == "ok"):
            return {**data, "status": "ok"}
        return data

    async def search(self, q: str, type_: str = "", page: int = 1, per_page: int = 5) -> dict:
        return await self._request("GET", "search", {"q": q, "type": type_, "page": page, "per_page": per_page})

    async def song(self, song_id: int) -> dict:
        return await self._request("GET", f"song/{song_id}")

    async def artists(self, q: str = "", letter: str = "", sort: str = "name", page: int = 1, per_page: int = 10) -> dict:
        params = {"page": page, "per_page": per_page, "sort": sort}
        if q:
            params["q"] = q
        if letter:
            params["letter"] = letter
        return await self._request("GET", "artists", params)

    async def artist(self, identifier: str, page: int = 1, per_page: int = 5, sort: str = "new") -> dict:
        return await self._request("GET", f"artist/{identifier}", {"page": page, "per_page": per_page, "sort": sort})

    async def latest(self, page: int = 1, per_page: int = 5) -> dict:
        return await self._request("GET", "latest", {"page": page, "per_page": per_page})

    async def popular(self, page: int = 1, per_page: int = 5) -> dict:
        return await self._request("GET", "popular", {"page": page, "per_page": per_page})

    async def cache_clear(self) -> dict:
        return await self._request("POST", "cache/clear", use_cache=False, max_attempts=1)

    async def publish_channel_post(self, post_id: int) -> dict:
        """Ask WordPress to publish one explicitly selected post exactly once."""
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

    # ---- Taranehyar account API methods (tara/v1) ----

    async def account_link(self, code: str, telegram_id: int, username: str = "", first_name: str = "") -> dict:
        telegram_id = self._telegram_id(telegram_id)
        code = str(code or "").strip().upper()
        if not re.fullmatch(r"[A-HJ-NP-Z2-9]{8}", code):
            raise ValueError("کد اتصال باید ۸ نویسهٔ معتبر باشد.")
        return await self._account_post(
            "link",
            telegram_id,
            {
                "code": code,
                "username": str(username or "")[:100],
                "first_name": str(first_name or "")[:100],
            },
        )

    async def account_me(self, telegram_id: int) -> dict:
        telegram_id = self._telegram_id(telegram_id)
        return await self._request(
            "GET", "me", {"telegram_id": telegram_id}, request_base=self.account_base
        )

    async def account_unlink(self, telegram_id: int) -> dict:
        return await self._account_post("unlink", telegram_id, {})

    async def account_follows(self, telegram_id: int) -> dict:
        telegram_id = self._telegram_id(telegram_id)
        return await self._request(
            "GET", "follows", {"telegram_id": telegram_id}, request_base=self.account_base
        )

    async def account_follow(self, telegram_id: int, artist: int | str, *, on: bool = True) -> dict:
        body = {"action": "add" if on else "remove"}
        if isinstance(artist, bool):
            raise ValueError("شناسه یا اسلاگ هنرمند نامعتبر است.")
        if isinstance(artist, int) or str(artist).isdigit():
            term_id = int(artist)
            if term_id <= 0:
                raise ValueError("شناسهٔ هنرمند باید مثبت باشد.")
            body["term_id"] = term_id
        else:
            slug = str(artist or "").strip()
            if not slug or len(slug) > 200:
                raise ValueError("اسلاگ هنرمند نامعتبر است.")
            body["slug"] = slug
        return await self._account_post("follow", telegram_id, body)

    async def account_feed(self, telegram_id: int, since: int = 0, limit: int = 10) -> dict:
        telegram_id = self._telegram_id(telegram_id)
        since = max(0, int(since))
        limit = max(1, min(50, int(limit)))
        return await self._request(
            "GET",
            "feed",
            {"telegram_id": telegram_id, "since": since, "limit": limit},
            request_base=self.account_base,
        )

    async def account_like(self, telegram_id: int, post_id: int, *, on: bool = True) -> dict:
        post_id = int(post_id)
        if post_id <= 0:
            raise ValueError("شناسهٔ آهنگ باید مثبت باشد.")
        return await self._account_post(
            "like", telegram_id, {"post_id": post_id, "action": "add" if on else "remove"}
        )

    async def account_playlists(self, telegram_id: int) -> dict:
        telegram_id = self._telegram_id(telegram_id)
        return await self._request(
            "GET", "playlists", {"telegram_id": telegram_id}, request_base=self.account_base
        )

    async def account_playlist(
        self,
        telegram_id: int,
        action: str,
        *,
        key: str = "",
        name: str = "",
        post_id: int | None = None,
    ) -> dict:
        action = str(action or "").strip().lower()
        if action not in {"create", "add", "remove", "delete", "rename"}:
            raise ValueError("عملیات پلی‌لیست نامعتبر است.")
        body = {"action": action}
        if key:
            body["key"] = str(key)[:100]
        if name:
            body["name"] = str(name)[:120]
        if post_id is not None:
            post_id = int(post_id)
            if post_id <= 0:
                raise ValueError("شناسهٔ آهنگ باید مثبت باشد.")
            body["post_id"] = post_id
        return await self._account_post("playlist", telegram_id, body)

    async def account_notifications(self, telegram_id: int) -> dict:
        telegram_id = self._telegram_id(telegram_id)
        return await self._request(
            "GET", "notifications", {"telegram_id": telegram_id}, request_base=self.account_base
        )

    async def account_read(self, telegram_id: int) -> dict:
        return await self._account_post("read", telegram_id, {})

    def invalidate_account(self, telegram_id: int) -> None:
        telegram_id = self._telegram_id(telegram_id)
        self.invalidate(f"('telegram_id', {telegram_id})")

    def invalidate(self, pattern: str = ""):
        if not pattern:
            self._cache.clear()
        else:
            for key in list(self._cache):
                if pattern in key:
                    del self._cache[key]


class APIError(Exception):
    def __init__(self, status: int, message: str, code: str = ""):
        self.status = int(status)
        self.message = str(message or "")
        self.code = str(code or "")
        super().__init__(f"[{self.status}] {self.message}")


# Global singleton: music endpoints use ahangban/v1; account endpoints use tara/v1.
api = AhangBanAPI(account_base=TARA_API_BASE)
