Phase A – Repo/Extension Setup (Foundation)
Ziel: Bau- und Test-bereite Extension-Struktur.
Tasks

- Composer Extension Skeleton "sg_apicore"

- DI/Services.yaml Grundgerüst

- RequestMiddleware registrieren (Configuration/RequestMiddlewares.php)

- Basiskonfig (Configuration/ApiCore.php oder ext_conf_template.txt + PHP Config Reader)

- Basic CI Scripts (lokal): phpunit, phpstan, cs-fixer

DoD

- Installation in TYPO3 12/13 möglich

- Middleware läuft und liefert testweise JSON bei /api/health

Implementiere eine TYPO3 Extension (Composer-basiert) "sg_apicore" für TYPO3 ^12.4 || ^13.0.
Erzeuge:

- composer.json + ext_emconf.php + ext_localconf.php (minimal)
- Configuration/RequestMiddlewares.php: Middleware registriert und fängt Pfadpräfix /api ab
- Middleware: ApiRequestMiddleware. Wenn Pfad mit /api beginnt, antworte auf GET /api/health mit JSON {"status":"ok"}
  und Status 200.
- Unit-Test für Middleware (PHPUnit), der die Response prüft.
- README: Installation + Testaufruf.
  Nutze PSR-7/PSR-15 sauber, keine Extbase-Abhängigkeit.

---

Phase B – Routing + Versionierung + Multi‑API
Ziel: Requests deterministisch auf Handler mappen.
Entscheidungen (als ADR)

- Router: FastRoute (leicht) vs Symfony Routing (reicher)

      * API/Version Pattern:

         * Option 1: /api/{apiId}/v1/...

         * Option 2: /api/v1/... + apiId über Header/Host

Tasks

- RouteCollection pro (apiId, version)

      * Mounting mehrerer APIs

      * routes.yaml optional: Overrides/Custom Routes

      * Endpoint-Discovery (zunächst statisch: Controller Attribute)

DoD

- 2 APIs parallel möglich (z.B. public und partner)

       * /api/public/v1/health & /api/partner/v1/health funktionieren

Erweitere ApiRequestMiddleware um Routing:

- Unterstütze base pattern /api/{apiId}/v{version}/...
- Implementiere ApiRegistry (Konfig): apiId => erlaubte Versionen + basePath
- Implementiere Router (z.B. FastRoute), der GET /api/{apiId}/v1/health auf HealthController::health mappt
- Liefere 404 Problem JSON für unbekannte Routes.
- Tests: Routing match + 404.
- Doku: Konfigbeispiel für 2 APIs (public/partner).

---

Phase C – Tenant Context (Multi‑Tenant)
Ziel: Tenant ist immer bekannt und durchsetzbar.
Tasks

- TenantResolverInterface

          * Implementierungen:

             * Header Resolver (X-Tenant-Id)

             * optional Host Resolver (tenant.example.com)

             * optional Path Resolver (/t/{tenant}/api/...)

                * TenantContext (immutable) in Request Attribute

                * Validierung: unknown tenant → 404 oder 400 (als config)

DoD

- Request ohne Tenant wird sauber abgelehnt (konfigurierbar)

       * Token ist an Tenant gebunden (Security folgt in Phase D)

Füge Multi-Tenant hinzu:

- TenantResolverInterface + HeaderTenantResolver (X-Tenant-Id)
- TenantContext wird als Request attribute gesetzt (tenantId)
- Wenn tenant fehlt: 400 Problem JSON (konfigurierbar)
- Tests: fehlender Header => 400, vorhandener => weiter.
- Doku: Wie Tenant über Header gesetzt wird.

---

Phase D – Security (Bearer Token + Scopes + LoginProvider)
Ziel: Sicherer Zugriff, erweiterbar.
DB / Domain

- Tabelle tx_apicore_token:

    * uid, pid

        * tenant_id (string)

        * api_id (string)

        * token_hash (string, z.B. sha256)

        * label (string)

        * scopes (json)

        * expires_at, revoked_at, last_used_at

        * optional: allowed_ips / rate_limit (später)

            * optional Tabelle tx_apicore_tenant (oder Tenant aus externer Quelle)

Tasks

- LoginProviderInterface → authenticate(request): ?AuthContext

       * BearerTokenProvider:

          * liest Authorization: Bearer …

          * hasht Token, lädt Record, prüft Tenant + API + Expiry + Revocation

          * liefert Scopes + Token Identity

             * Scope Enforcement pro Endpoint (Attribute #[RequireScopes([...])])

             * Mechanismus zur Erweiterung:

                * AdditionalLoginProvider kann User Identity liefern (z.B. “User Login”), Scopes erweitern

                * Merge-Regeln (z.B. tokenScopes ∩ userScopes oder tokenScopes ∪ userScopes – als ADR)

DoD

- Endpunkt kann Scopes verlangen, sonst 403

       * Token kann pro Tenant + API getrennt sein

       * Provider-Chain funktioniert (Bearer + optional UserLogin)

Implementiere Bearer Token Auth:

- DB Tabelle tx_apicore_token (TCA optional erstmal minimal), Repository zum Laden per token_hash + apiId + tenantId
- Token hash: sha256(plaintext token), constant-time compare
- Authorization: Bearer <token> wird verarbeitet
- AuthContext enthält: apiId, tenantId, tokenUid, scopes[]
- ApiEndpoint Attribute kann requiredScopes definieren. Wenn scopes fehlen => 403 Problem JSON
- Tests: 401 ohne Token, 403 ohne Scope, 200 mit Scope
- Doku: Token anlegen (zunächst manuell), Format der Scopes

---

Phase E – Endpoint Metadata (Attributes) + Controller Pattern
Ziel: Business-Endpunkte sauber deklarieren.
Tasks

- Attribute:

         * #[ApiEndpoint(path, methods, summary, tags, requestSchema?, responseSchema?, scopes?)]

         * #[ApiQueryParam(name, type, required, description)]

         * #[ApiPathParam(...)]

            * “Handler”-Konzept:

               * Controller-Methode erhält Request + Context, gibt DTO/Array oder Response zurück

                  * Standard Response Envelope (optional):

                     * {"data": ..., "meta": ...} (konfigurierbar)

                        * Error Format vereinheitlichen (Problem JSON)

DoD

- 2–3 Business-Endpunkte als Beispiel

           * OpenAPI Infos aus Attributes ableitbar (Phase F)

---

Phase F – OpenAPI Generator + Swagger UI
Ziel: Doku ist first-class und releasefähig.
Tasks

- OpenAPI Spec Builder aus:

             * APIs Registry (apiId + version)

             * Routes + Attributes

                * Export:

                   * /api/{apiId}/v{n}/docs.json

                   * optional /docs.yaml

                      * Viewer:

                         * /api/{apiId}/v{n}/docs/ui (Swagger UI Assets)

                            * CLI Command:

                               * typo3 api:openapi:generate --api=public --version=1 --format=json --out=...

DoD

- Spec enthält Security Schemes (Bearer)

          * Spec enthält Endpunkte + Parameter + Response Codes

          * Swagger UI zeigt alles korrekt an

Baue OpenAPI Generator:

- Sammle Endpunkte (aus Attributes + Router)
- Generiere OpenAPI 3 JSON unter /api/{apiId}/v{n}/docs.json
- Swagger UI unter /api/{apiId}/v{n}/docs/ui, nutzt docs.json
- Security Scheme: HTTP bearer
- Tests: docs.json liefert valide JSON-Struktur + enthält mindestens 1 path
- Doku: Wie man docs aufruft und exportiert.

---

Phase G – Logging (konfigurierbar, redacted)
Ziel: Nachvollziehbarkeit ohne Datenleak.
Tasks

- Logging Middleware/Decorator im API Pipeline

             * Konfiguration:

                * global + pro Endpoint

                * logRequest/logResponse/logHeaders/logBody

                * redaction keys (token, authorization, password, secret)

                   * Korrelations-ID (Request ID)

DoD

- Logs sind eindeutig (apiId, tenantId, endpointId, status, duration)

          * Sensitive Daten werden maskiert

---

Phase H – TCA Mapper (Basis)
Ziel: TYPO3‑Records sauber mappen.
Tasks

- TcaMapper:

            * Whitelist-Felder

            * Relations (zunächst simpel: uid lists / resolved via Repository optional)

            * Field transformers (DateTime, int, bool)

               * DTO/Schema Integration: Mapper liefert arrays passend zu OpenAPI schema (wenn möglich)

DoD

- Beispiel: Tabelle tt_content oder eigene Demo-Tabelle → JSON Ausgabe über Endpunkt

---

Phase I – Dokumentation & Open Source Readiness
Ziel: Releasefähig.
Tasks

- README: Quickstart, Installation, Konfiguration

         * docs/:

            * Tenants, APIs, Auth & Scopes, Writing Endpoints, OpenAPI, Logging, TCA Mapper

               * docs/adr/: Router Wahl, Scope Merge Regeln, Tenant Auflösung

               * “Example extension” oder examples/ Ordner

DoD

- “Hello world API” in 10 Minuten nachvollziehbar

      * Alle Konfigurationen dokumentiert

      * Changelog / Versioning Policy (SemVer)
