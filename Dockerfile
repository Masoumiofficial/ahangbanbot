FROM python:3.11-slim

WORKDIR /app

# FFmpeg with libopus is used only when WordPress cannot queue a voice preview; curl serves healthchecks.
RUN apt-get update && apt-get install -y --no-install-recommends curl ffmpeg && rm -rf /var/lib/apt/lists/*

COPY bot/requirements.txt ./requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

COPY bot ./bot
COPY .env.example .env.example

ENV PYTHONUNBUFFERED=1
ENV PORT=8000
ENV AHANGBAN_DB_PATH=/data/ahangban.db

EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=3s --retries=3 CMD curl -fsS http://127.0.0.1:8000/health || exit 1

RUN mkdir -p /data

CMD ["python", "-m", "bot.main"]
