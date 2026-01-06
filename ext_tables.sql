CREATE TABLE tx_apicore_token (
	uid              int(11)                  NOT NULL auto_increment,
	pid              int(11)      DEFAULT '0' NOT NULL,

	tenant_id        varchar(255) DEFAULT ''  NOT NULL,
	api_id           varchar(255) DEFAULT ''  NOT NULL,
	token_hash       varchar(64)  DEFAULT ''  NOT NULL,
	user_id          int(11)      DEFAULT '0' NOT NULL,
	is_refresh_token tinyint(4)   DEFAULT '0' NOT NULL,
	label            varchar(255) DEFAULT ''  NOT NULL,
	scopes           text,
	expires_at       int(11)      DEFAULT '0' NOT NULL,
	revoked_at       int(11)      DEFAULT '0' NOT NULL,
	last_used_at     int(11)      DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY parent(pid),
	KEY tenant_api_hash(tenant_id, api_id, token_hash)
);
