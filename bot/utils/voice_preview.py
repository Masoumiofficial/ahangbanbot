"""Create and send short OGG/Opus channel previews from the Python bot environment."""

import asyncio
import logging
import os
import re
import shutil
import tempfile
from collections.abc import Awaitable, Callable
from pathlib import Path

from telegram.error import BadRequest, Forbidden, InvalidToken, TelegramError

log = logging.getLogger("ahangban.voice_preview")

_ALLOWED_DURATIONS = {15, 30, 45, 60}
_MAX_OUTPUT_BYTES = 10 * 1024 * 1024


def normalize_channel_target(value: object) -> str:
    """Accept only Telegram channel usernames or numeric supergroup/channel IDs."""
    target = str(value or "").strip()
    if re.fullmatch(r"-100[0-9]{6,20}", target):
        return target
    username = target.removeprefix("@")
    if re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{4,31}", username):
        return "@" + username
    return ""


async def _ffmpeg_supports_opus(ffmpeg: str) -> bool:
    process = None
    try:
        process = await asyncio.create_subprocess_exec(
            ffmpeg,
            "-hide_banner",
            "-encoders",
            stdin=asyncio.subprocess.DEVNULL,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.STDOUT,
        )
        output, _ = await asyncio.wait_for(process.communicate(), timeout=10)
        return process.returncode == 0 and b"libopus" in output.lower()
    except (OSError, TimeoutError):
        if process and process.returncode is None:
            process.kill()
            await process.wait()
        return False


async def _convert_to_telegram_voice(ffmpeg: str, source_path: str, output_path: str, duration: int) -> bool:
    process = None
    try:
        process = await asyncio.create_subprocess_exec(
            ffmpeg,
            "-nostdin",
            "-hide_banner",
            "-loglevel",
            "error",
            "-y",
            "-i",
            source_path,
            "-t",
            str(duration),
            "-map_metadata",
            "-1",
            "-vn",
            "-c:a",
            "libopus",
            "-b:a",
            "48k",
            "-ac",
            "1",
            "-ar",
            "48000",
            "-application",
            "voip",
            "-f",
            "ogg",
            output_path,
            stdin=asyncio.subprocess.DEVNULL,
            stdout=asyncio.subprocess.DEVNULL,
            stderr=asyncio.subprocess.PIPE,
        )
        _, stderr = await asyncio.wait_for(process.communicate(), timeout=55)
        if process.returncode != 0:
            log.info("FFmpeg could not encode channel preview (exit=%s): %s", process.returncode, stderr[-400:].decode("utf-8", "replace"))
            return False
        output = Path(output_path)
        return output.is_file() and 0 < output.stat().st_size <= _MAX_OUTPUT_BYTES
    except (OSError, TimeoutError) as exc:
        if process and process.returncode is None:
            process.kill()
            await process.wait()
        log.info("FFmpeg preview conversion failed: %s", type(exc).__name__)
        return False


def _remove_file(path: str | None) -> None:
    if not path:
        return
    try:
        os.unlink(path)
    except FileNotFoundError:
        pass
    except OSError:
        log.warning("could not remove temporary voice-preview file")


async def send_python_voice_preview(
    *,
    bot,
    channel_target: object,
    reply_to_message_id: int,
    sources: dict,
    duration: int,
    download_media: Callable[[str], Awaitable[tuple[str, str, str]]],
) -> dict:
    """Try conversion locally only after WordPress reports it could not queue a preview.

    Returns one of ``sent``, ``unavailable``, ``failed``, or ``ambiguous``. The Telegram API
    send is attempted at most once; a network error is never retried because acceptance may
    have happened before its response was lost.
    """
    target = normalize_channel_target(channel_target)
    try:
        reply_id = int(reply_to_message_id or 0)
        duration = int(duration or 60)
    except (TypeError, ValueError):
        return {"status": "failed", "reason": "invalid_target_or_reply"}
    if not target or reply_id <= 0:
        return {"status": "failed", "reason": "invalid_target_or_reply"}
    if duration not in _ALLOWED_DURATIONS:
        duration = 60

    candidates = []
    if isinstance(sources, dict):
        for quality in ("128", "320"):
            url = str(sources.get(quality) or "").strip()
            if url and url not in candidates:
                candidates.append(url)
    if not candidates:
        return {"status": "unavailable", "reason": "no_audio_source"}

    ffmpeg = shutil.which("ffmpeg")
    if not ffmpeg or not await _ffmpeg_supports_opus(ffmpeg):
        return {"status": "unavailable", "reason": "ffmpeg_or_libopus_missing"}

    last_failure = "source_or_conversion_failed"
    for url in candidates:
        source_path = output_path = None
        try:
            source_path, _content_type, _filename = await download_media(url)
            fd, output_path = tempfile.mkstemp(prefix="ahangban-voice-", suffix=".ogg")
            os.close(fd)
            if not await _convert_to_telegram_voice(ffmpeg, source_path, output_path, duration):
                last_failure = "conversion_failed"
                continue
            try:
                sent_message = await bot.send_voice(
                    chat_id=target,
                    voice=Path(output_path),
                    filename="ahangban-preview.ogg",
                    duration=duration,
                    reply_to_message_id=reply_id,
                )
            except (BadRequest, Forbidden, InvalidToken) as exc:
                log.warning("Telegram definitively rejected voice preview: %s", type(exc).__name__)
                return {"status": "failed", "reason": type(exc).__name__}
            except TelegramError as exc:
                log.warning("Telegram voice preview result is ambiguous: %s", type(exc).__name__)
                return {"status": "ambiguous", "reason": type(exc).__name__}
            except Exception as exc:
                log.warning("Voice preview send result is ambiguous: %s", type(exc).__name__)
                return {"status": "ambiguous", "reason": type(exc).__name__}

            if not getattr(sent_message, "message_id", None):
                return {"status": "ambiguous", "reason": "missing_message_id"}
            return {"status": "sent", "message_id": int(sent_message.message_id)}
        except Exception as exc:
            last_failure = type(exc).__name__
            log.info("Could not prepare one voice-preview source: %s", last_failure)
        finally:
            _remove_file(source_path)
            _remove_file(output_path)

    return {"status": "failed", "reason": last_failure}
