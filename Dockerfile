FROM python:3.11-slim

WORKDIR /app

# System deps for healthcheck
RUN apt-get update && apt-get install -y --no-install-recommends curl && rm -rf /var/lib/apt/lists/*

COPY bot/requirements.txt ./requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

COPY bot ./bot
COPY .env.example .env.example

ENV PYTHONUNBUFFERED=1
ENV AHANGBAN_DB_PATH=/data/ahangban.db

RUN mkdir -p /data

CMD ["python", "-m", "bot.main"]
