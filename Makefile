RSYNC ?= rsync
SSH ?= ssh

RSYNC_FLAGS := -avz --omit-dir-times
RSYNC_EXCLUDES := \
	--exclude '.git/' \
	--exclude '.DS_Store' \
	--exclude '.make.local' \
	--exclude 'Makefile' \
	--exclude 'data/users.json' \
	--exclude 'data/logs/actions.log' \
	--exclude 'storage/files/*'

.PHONY: deploy deploy-dry-run remote-init check-deploy-vars

-include .make.local

.PHONY: deploy deploy-dry-run remote-init

check-deploy-vars:
	@test -n "$(DEPLOY_HOST)" || (echo "DEPLOY_HOST is required. Example: make deploy DEPLOY_HOST=user@example-host DEPLOY_PATH=/path/to/browsebox" && exit 1)
	@test -n "$(DEPLOY_PATH)" || (echo "DEPLOY_PATH is required. Example: make deploy DEPLOY_HOST=user@example-host DEPLOY_PATH=/path/to/browsebox" && exit 1)

deploy: check-deploy-vars remote-init
	$(RSYNC) $(RSYNC_FLAGS) $(RSYNC_EXCLUDES) ./ $(DEPLOY_HOST):$(DEPLOY_PATH)/
	$(SSH) $(DEPLOY_HOST) "\
		mkdir -p $(DEPLOY_PATH)/data/logs $(DEPLOY_PATH)/storage/files && \
		touch $(DEPLOY_PATH)/data/users.json $(DEPLOY_PATH)/data/logs/actions.log $(DEPLOY_PATH)/storage/files/.gitkeep && \
		chgrp -R www-data $(DEPLOY_PATH)/data $(DEPLOY_PATH)/storage 2>/dev/null || true && \
		find $(DEPLOY_PATH)/data $(DEPLOY_PATH)/storage -type d -exec chmod 2775 {} \; 2>/dev/null || true && \
		find $(DEPLOY_PATH)/data $(DEPLOY_PATH)/storage -type f -exec chmod 664 {} \; 2>/dev/null || true \
	"

deploy-dry-run: check-deploy-vars remote-init
	$(RSYNC) $(RSYNC_FLAGS) --dry-run $(RSYNC_EXCLUDES) ./ $(DEPLOY_HOST):$(DEPLOY_PATH)/

remote-init: check-deploy-vars
	$(SSH) $(DEPLOY_HOST) "\
		mkdir -p \
			$(DEPLOY_PATH)/app \
			$(DEPLOY_PATH)/config \
			$(DEPLOY_PATH)/data/logs \
			$(DEPLOY_PATH)/public/assets \
			$(DEPLOY_PATH)/scripts \
			$(DEPLOY_PATH)/storage/files \
	"
