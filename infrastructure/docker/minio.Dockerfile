FROM debian:bookworm-slim AS download
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl && rm -rf /var/lib/apt/lists/*
ARG TARGETARCH
RUN case "$TARGETARCH" in \
      amd64) checksum=7c5bd8512c6e966455b1d198209358b2d191c77a83ab377c4073281065fb855f ;; \
      arm64) checksum=5c83cd2cf151717ba0243f73e1c7802ff36e272b67144bdd7f1f7d684fd6f03d ;; \
      *) exit 1 ;; \
    esac && \
    curl --fail --location --retry 3 "https://github.com/minio/minio/releases/download/RELEASE.2025-09-07T16-13-09Z/minio.linux-$TARGETARCH.RELEASE.2025-09-07T16-13-09Z" --output /minio && \
    echo "$checksum  /minio" | sha256sum --check - && chmod 755 /minio && /minio --version
FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates && rm -rf /var/lib/apt/lists/*
COPY --from=download /minio /usr/local/bin/minio
EXPOSE 9000 9001
ENTRYPOINT ["minio"]
CMD ["server", "/data", "--console-address", ":9001"]
