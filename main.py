"""Root entry point for Python PaaS builders that require a top-level .py file."""
import asyncio

from bot.main import main


if __name__ == "__main__":
    asyncio.run(main())
