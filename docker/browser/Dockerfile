FROM python:3.12-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    libgtk-3-0 libx11-xcb1 libasound2 libdbus-glib-1-2 libxt6 libxrandr2 libgbm1 \
    libnss3 libxcomposite1 libxdamage1 libpango-1.0-0 libatk1.0-0 libxfixes3 fonts-liberation \
    && rm -rf /var/lib/apt/lists/*
RUN pip install --no-cache-dir camoufox==0.5.8 fastapi==0.161.0 uvicorn==0.54.0 \
    && python -m camoufox fetch
WORKDIR /service
COPY app.py /service/app.py
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8080"]
