ARG BASE=debian:bookworm-slim
FROM ${BASE}
RUN apt-get update && apt-get install -y --no-install-recommends wrk=4.1.0-3+b2 python3 ca-certificates && rm -rf /var/lib/apt/lists/*
COPY loadgen/ /bench/
CMD ["sleep", "infinity"]
