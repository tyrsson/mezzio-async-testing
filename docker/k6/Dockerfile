FROM grafana/k6:latest

# Mount point for k6 test scripts (mapped from test/k6/ on the host)
WORKDIR /scripts

# The container stays idle; scripts are run via:
#   docker compose exec k6 k6 run /scripts/baseline.js
#   docker compose exec k6 k6 run --out json=/scripts/results/baseline.json /scripts/baseline.js
ENTRYPOINT ["sleep", "infinity"]
