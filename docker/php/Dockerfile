# WARNING: The `latest` tag pulled on 2026-04-18 introduced a regression in
# Async\Pool concurrent access (zend_mm_heap corrupted at 2+ VUs).
# Pin to a known-good digest once identified.
# To get the digest of a running container: docker inspect <container> | grep RepoDigests
# Then use: FROM trueasync/php-true-async@sha256:<digest>
FROM trueasync/php-true-async:latest AS base

WORKDIR /var/www/app

CMD ["php", "bin/mezzio-async", "start"]
