"""Optional FFmpeg publisher for the WordPress radio source and Telegram Live Stream."""

import asyncio
import ipaddress
import logging
import shutil
from urllib.parse import quote, urlsplit, urlunsplit

from ..config import RADIO_RTMP_KEY, RADIO_RTMP_SERVER, RADIO_STREAM_URL

log = logging.getLogger("ahangban.radio_stream")


def radio_stream_requested() -> bool:
    """Whether any private live-stream setting was supplied."""
    return any((RADIO_STREAM_URL, RADIO_RTMP_SERVER, RADIO_RTMP_KEY))


def radio_stream_configured() -> bool:
    """Streaming starts only when the source and Telegram RTMP credentials are all present."""
    return all((RADIO_STREAM_URL, RADIO_RTMP_SERVER, RADIO_RTMP_KEY))


def _public_host(hostname: str) -> bool:
    host = (hostname or "").lower().rstrip(".")
    if not host or host == "localhost" or host.endswith((".localhost", ".local", ".internal")):
        return False
    try:
        address = ipaddress.ip_address(host)
    except ValueError:
        return "." in host
    return address.is_global


def _validate_source_url(source_url: str) -> str:
    try:
        parsed = urlsplit(str(source_url or "").strip())
        parsed.port  # Validate malformed ports before passing the URL to FFmpeg.
    except ValueError as exc:
        raise ValueError("invalid radio source URL") from exc
    if (
        parsed.scheme != "https"
        or not parsed.hostname
        or parsed.username
        or parsed.password
        or parsed.fragment
        or not _public_host(parsed.hostname)
    ):
        raise ValueError("radio source must be a public HTTPS URL")
    return parsed.geturl()


def _build_rtmp_output_url(server_url: str, stream_key: str) -> str:
    try:
        parsed = urlsplit(str(server_url or "").strip())
        parsed.port  # Validate malformed ports before passing the URL to FFmpeg.
    except ValueError as exc:
        raise ValueError("invalid Telegram RTMP server URL") from exc
    key = str(stream_key or "").strip()
    if (
        parsed.scheme not in ("rtmp", "rtmps")
        or not parsed.hostname
        or parsed.username
        or parsed.password
        or parsed.query
        or parsed.fragment
        or not _public_host(parsed.hostname)
        or not key
        or "\n" in key
        or "\r" in key
    ):
        raise ValueError("invalid Telegram RTMP credentials")
    path = parsed.path.rstrip("/") + "/" + quote(key, safe="")
    return urlunsplit((parsed.scheme, parsed.netloc, path, "", ""))


def build_ffmpeg_command(
    ffmpeg: str,
    source_url: str,
    rtmp_server: str,
    stream_key: str,
) -> list[str]:
    """Build an audio-radio RTMP stream with a static branded-color video slate."""
    source = _validate_source_url(source_url)
    destination = _build_rtmp_output_url(rtmp_server, stream_key)
    return [
        ffmpeg,
        "-hide_banner",
        "-nostdin",
        "-loglevel",
        "error",
        "-reconnect",
        "1",
        "-reconnect_streamed",
        "1",
        "-reconnect_delay_max",
        "10",
        "-i",
        source,
        "-re",
        "-f",
        "lavfi",
        "-i",
        "color=c=0x185ADB:s=1280x720:r=25",
        "-map",
        "1:v:0",
        "-map",
        "0:a:0",
        "-c:v",
        "libx264",
        "-preset",
        "veryfast",
        "-tune",
        "stillimage",
        "-pix_fmt",
        "yuv420p",
        "-r",
        "25",
        "-g",
        "50",
        "-b:v",
        "500k",
        "-maxrate",
        "500k",
        "-bufsize",
        "1000k",
        "-c:a",
        "aac",
        "-b:a",
        "128k",
        "-ar",
        "44100",
        "-ac",
        "2",
        "-f",
        "flv",
        destination,
    ]


def _ffmpeg_candidates() -> list[str]:
    candidates = []
    try:
        from imageio_ffmpeg import get_ffmpeg_exe

        bundled_ffmpeg = get_ffmpeg_exe()
    except (ImportError, OSError, RuntimeError):
        bundled_ffmpeg = ""
    if bundled_ffmpeg:
        candidates.append(bundled_ffmpeg)
    system_ffmpeg = shutil.which("ffmpeg")
    if system_ffmpeg and system_ffmpeg not in candidates:
        candidates.append(system_ffmpeg)
    return candidates


async def _stop_process(process: asyncio.subprocess.Process) -> None:
    if process.returncode is not None:
        return
    try:
        process.terminate()
    except ProcessLookupError:
        await process.wait()
        return
    try:
        await asyncio.wait_for(process.wait(), timeout=8)
    except TimeoutError:
        try:
            process.kill()
        except ProcessLookupError:
            pass
        await process.wait()


async def run_radio_stream() -> None:
    """Keep an optional Telegram channel live stream running, without logging its secrets."""
    if not radio_stream_requested():
        return
    if not radio_stream_configured():
        log.warning(
            "Radio live stream is disabled: configure the HTTPS source, RTMP server, and stream key."
        )
        return

    try:
        command = build_ffmpeg_command(
            "ffmpeg",
            RADIO_STREAM_URL,
            RADIO_RTMP_SERVER,
            RADIO_RTMP_KEY,
        )
    except ValueError:
        log.error("Radio live stream settings are invalid; worker was not started.")
        return

    candidates = _ffmpeg_candidates()
    if not candidates:
        log.error("Radio live stream is disabled: FFmpeg is unavailable.")
        return
    command[0] = candidates[0]

    delay = 5
    while True:
        process = None
        try:
            started_at = asyncio.get_running_loop().time()
            process = await asyncio.create_subprocess_exec(
                *command,
                stdin=asyncio.subprocess.DEVNULL,
                stdout=asyncio.subprocess.DEVNULL,
                stderr=asyncio.subprocess.DEVNULL,
            )
            log.info("Radio stream worker connected to the configured Telegram live stream.")
            exit_code = await process.wait()
            if asyncio.get_running_loop().time() - started_at >= 60:
                delay = 5
            log.warning("Radio stream ended (exit=%s); retrying after %s seconds.", exit_code, delay)
        except asyncio.CancelledError:
            if process is not None:
                await _stop_process(process)
            raise
        except Exception as exc:
            log.warning("Radio stream worker failed (%s); retrying after %s seconds.", type(exc).__name__, delay)
        await asyncio.sleep(delay)
        delay = min(delay * 2, 60)
