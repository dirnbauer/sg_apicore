CREATE TABLE tx_apicore_token (
	uid          int(11)                  NOT NULL auto_increment,
	pid          int(11)      DEFAULT '0' NOT NULL,

	tenant_id    varchar(255) DEFAULT ''  NOT NULL,
	api_id       varchar(255) DEFAULT ''  NOT NULL,
	token        text,
	label        varchar(255) DEFAULT ''  NOT NULL,
	scopes       text,
	expires_at   int(11)      DEFAULT '0' NOT NULL,
	revoked_at   int(11)      DEFAULT '0' NOT NULL,
	last_used_at int(11)      DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY parent(pid),
	KEY tenant_api(tenant_id, api_id)
);
