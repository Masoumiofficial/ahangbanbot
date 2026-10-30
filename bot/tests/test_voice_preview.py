from pathlib import Path
from types import ModuleType, SimpleNamespace
from unittest.mock import AsyncMock, patch

import pytest
from telegram.error import NetworkError

from bot.utils.voice_preview import normalize_channel_target, send_python_voice_preview


def test_channel_target_normalization_is_limited_to_valid_telegram_destinations():
    assert normalize_channel_target("Ahangban_ir") == "@Ahangban_ir"
    assert normalize_channel_target("@Ahangban_ir") == "@Ahangban_ir"
    assert normalize_channel_target("-1001234567890") == "-1001234567890"
    assert normalize_channel_target("https://t.me/Ahangban_ir") == ""
    assert normalize_channel_target("@bad/name") == ""
    assert normalize_channel_target("127.0.0.1") == ""


@pytest.mark.asyncio
async def test_voice_fallback_is_unavailable_without_python_ffmpeg():
    bot = SimpleNamespace(send_voice=AsyncMock())
    download = AsyncMock()
    with patch("bot.utils.voice_preview._ffmpeg_candidates", return_value=[]):
        result = await send_python_voice_preview(
            bot=bot,
            channel_target="@Ahangban_ir",
            reply_to_message_id=40,
            sources={"128": "https://cdn.example.org/track.mp3"},
            duration=60,
            download_media=download,
        )
    assert result["status"] == "unavailable"
    download.assert_not_awaited()
    bot.send_voice.assert_not_awaited()


@pytest.mark.asyncio
async def test_voice_fallback_uses_bundled_ffmpeg_when_system_binary_is_missing(tmp_path):
    source = tmp_path / "track.mp3"
    source.write_bytes(b"audio source")
    bot = SimpleNamespace(send_voice=AsyncMock(return_value=SimpleNamespace(message_id=42)))
    download = AsyncMock(return_value=(str(source), "audio/mpeg", "track.mp3"))
    bundled_ffmpeg = ModuleType("imageio_ffmpeg")
    bundled_ffmpeg.get_ffmpeg_exe = lambda: "/python-packages/imageio_ffmpeg/ffmpeg"

    async def convert(ffmpeg, _source, output, _duration):
        assert ffmpeg == "/python-packages/imageio_ffmpeg/ffmpeg"
        Path(output).write_bytes(b"ogg opus")
        return True

    with patch.dict("sys.modules", {"imageio_ffmpeg": bundled_ffmpeg}), patch(
        "bot.utils.voice_preview.shutil.which", return_value=None
    ), patch(
        "bot.utils.voice_preview._ffmpeg_supports_opus", new=AsyncMock(return_value=True)
    ) as supports_opus, patch("bot.utils.voice_preview._convert_to_telegram_voice", new=convert):
        result = await send_python_voice_preview(
            bot=bot,
            channel_target="@Ahangban_ir",
            reply_to_message_id=40,
            sources={"128": "https://cdn.example.org/track.mp3"},
            duration=30,
            download_media=download,
        )

    assert result == {"status": "sent", "message_id": 42}
    supports_opus.assert_awaited_once_with("/python-packages/imageio_ffmpeg/ffmpeg")
    bot.send_voice.assert_awaited_once()


@pytest.mark.asyncio
async def test_voice_fallback_sends_one_converted_ogg_reply(tmp_path):
    source = tmp_path / "track.mp3"
    source.write_bytes(b"audio source")
    bot = SimpleNamespace(send_voice=AsyncMock(return_value=SimpleNamespace(message_id=41)))
    download = AsyncMock(return_value=(str(source), "audio/mpeg", "track.mp3"))

    async def convert(_ffmpeg, _source, output, _duration):
        Path(output).write_bytes(b"ogg opus")
        return True

    with patch("bot.utils.voice_preview.shutil.which", return_value="/usr/bin/ffmpeg"), patch(
        "bot.utils.voice_preview._ffmpeg_supports_opus", new=AsyncMock(return_value=True)
    ), patch("bot.utils.voice_preview._convert_to_telegram_voice", new=convert):
        result = await send_python_voice_preview(
            bot=bot,
            channel_target="@Ahangban_ir",
            reply_to_message_id=40,
            sources={"128": "https://cdn.example.org/track.mp3"},
            duration=45,
            download_media=download,
        )

    assert result == {"status": "sent", "message_id": 41}
    download.assert_awaited_once_with("https://cdn.example.org/track.mp3")
    bot.send_voice.assert_awaited_once()
    kwargs = bot.send_voice.await_args.kwargs
    assert kwargs["chat_id"] == "@Ahangban_ir"
    assert kwargs["reply_to_message_id"] == 40
    assert "allow_sending_without_reply" not in kwargs
    assert kwargs["duration"] == 45
    assert isinstance(kwargs["voice"], Path)
    assert kwargs["filename"] == "ahangban-preview.ogg"
    assert kwargs["voice"].suffix == ".ogg"


@pytest.mark.asyncio
async def test_voice_fallback_does_not_retry_an_ambiguous_telegram_send(tmp_path):
    source = tmp_path / "track.mp3"
    source.write_bytes(b"audio source")
    bot = SimpleNamespace(send_voice=AsyncMock(side_effect=NetworkError("connection lost")))
    download = AsyncMock(return_value=(str(source), "audio/mpeg", "track.mp3"))

    async def convert(_ffmpeg, _source, output, _duration):
        Path(output).write_bytes(b"ogg opus")
        return True

    with patch("bot.utils.voice_preview.shutil.which", return_value="/usr/bin/ffmpeg"), patch(
        "bot.utils.voice_preview._ffmpeg_supports_opus", new=AsyncMock(return_value=True)
    ), patch("bot.utils.voice_preview._convert_to_telegram_voice", new=convert):
        result = await send_python_voice_preview(
            bot=bot,
            channel_target="-1001234567890",
            reply_to_message_id=40,
            sources={
                "128": "https://cdn.example.org/track-128.mp3",
                "320": "https://cdn.example.org/track-320.mp3",
            },
            duration=60,
            download_media=download,
        )

    assert result["status"] == "ambiguous"
    bot.send_voice.assert_awaited_once()
    download.assert_awaited_once_with("https://cdn.example.org/track-128.mp3")
