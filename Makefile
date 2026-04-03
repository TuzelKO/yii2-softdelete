.DEFAULT_GOAL:
	@echo "╔════════════════════════════════════════════════════════════════════╗"
	@echo "║                        Available commands                          ║"
	@echo "╚════════════════════════════════════════════════════════════════════╝"
	@awk 'BEGIN { \
		FS = ":.*?## "; \
		section = "General"; \
	} \
	/^#-+$$/ { \
		getline; \
		if ($$0 ~ /^# [^#]/) { \
			section = substr($$0, 3); \
		} \
		next; \
	} \
	/^[a-zA-Z_-]+:.*?## / { \
		if (!seen[section]++) { \
			print "\n\033[1m" section ":\033[0m"; \
		} \
		printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2; \
	}' $(MAKEFILE_LIST)
	@echo

help: .DEFAULT_GOAL ## Show this help
h: .DEFAULT_GOAL

.DEFAULT:
	@echo "[✘] ERROR! Unknown action or environment is not configured."
	@echo "[?] Use 'help' to get more information."

#--------------------------------------------------
# Utilities
#--------------------------------------------------

clean: ## Clean disk from Docker garbage
	@echo "[!] Cleaning up after Docker..."
	@sleep 1
	@echo "[!] Removing all unused containers..."
	@docker container prune --force
	@echo "[!] Removing all unused images..."
	@docker image prune --all --force
	@echo "[!] Removing all unused networks..."
	@docker network prune --force
	@echo "[✔] Done!"

#--------------------------------------------------
# Package testing
#--------------------------------------------------

test: ## Run tests
	@echo "[!] Running tests..."
	@docker run -v "./:/app/" -w "/app/" -it 1drop/php-utils:8.3 /bin/bash -c "composer update && phpunit --color=never"
	@echo "[✔] Done!"