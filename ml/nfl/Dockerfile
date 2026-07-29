FROM python:3.11.11-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    OMP_NUM_THREADS=1 \
    OPENBLAS_NUM_THREADS=1 \
    MKL_NUM_THREADS=1 \
    NUMBA_NUM_THREADS=1

RUN apt-get update \
    && apt-get install --yes --no-install-recommends libgomp1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.lock.txt pyproject.toml README.md ./
RUN pip install --no-cache-dir --requirement requirements.lock.txt

COPY src ./src
COPY config ./config
RUN pip install --no-cache-dir --no-deps .

RUN groupadd --system ml \
    && useradd --system --gid ml --create-home ml \
    && mkdir -p /data /artifacts \
    && chown -R ml:ml /data /artifacts

USER ml

ENTRYPOINT ["picksports-nfl-ml"]
CMD ["--help"]
