Phase A – Repo/Extension Setup (Foundation)
Ziel: Bau- und Test-bereite Extension-Struktur.
Tasks

- Composer Extension Skeleton "sg_apicore"
- DI/Services.yaml Grundgerüst
- RequestMiddleware registrieren (Configuration/RequestMiddlewares.php)
- Basiskonfig (Configuration/ApiCore.php oder ext_conf_template.txt + PHP Config Reader)
- Basic CI Scripts (lokal): phpunit, phpstan, cs-fixer

Definition of Done

- Installation in TYPO3 12/13 möglich
- Middleware läuft und liefert testweise JSON bei /api/health

Implementiere eine TYPO3 Extension (Composer-basiert) "sg_apicore" für TYPO3 ^12.4 || ^13.0.
Erzeuge (insofern noch nicht gemacht):

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
- API/Version Pattern:
- Option 1: /api/{apiId}/v1/...
- Option 2: /api/v1/... + apiId über Header/Host

Tasks

- RouteCollection pro (apiId, version)
- Mounting mehrerer APIs
- routes.yaml optional: Overrides/Custom Routes
- Endpoint-Discovery (zunächst statisch: Controller Attribute)

Definition of Done

- 2 APIs parallel möglich (z.B. public und partner)
- /api/public/v1/health & /api/partner/v1/health funktionieren

Erweitere ApiRequestMiddleware um Routing:

- Unterstütze base pattern /api/{apiId}/v{version}/...
- Implementiere ApiRegistry (Konfig): apiId => erlaubte Versionen + basePath
- Implementiere Router (z.B. FastRoute), der GET /api/{apiId}/v1/health auf HealthController::health mappt
- Liefere 404 Problem JSON für unbekannte Routes.
- Tests: Routing match + 404.
- Doku: Konfigbeispiel für 2 APIs (public/partner).

---

Phase C – Tenant Context (Multi-Tenant über TYPO3 Site)
Ziel : Jeder API-Request läuft **immer*- in einem eindeutigen **Tenant-Kontext**, der standardmäßig aus der **TYPO3 Site
*- (Host/Pfad) abgeleitet wird.
Damit bleibt die API in TYPO3 „site-spezifisch“, ohne dass ein zusätzlicher Header benötigt wird – und trotzdem ist der
Code später leicht auf echte Multi-Tenant-Strategien (Header/Host/Path) erweiterbar.

Konzept

- **Tenant = Site*- (Default): `tenantId` wird aus der ermittelten TYPO3 Site abgeleitet (z. B. `siteIdentifier`).
- TenantContext wird **früh*- im Request gesetzt (Request Attribute), damit:
    - Auth/Token-Prüfung Tokens **an tenantId*- binden kann
    - Logging, Rate-Limits, Feature Flags etc. tenant-aware werden können
- Optional: Zusätzliche Resolver (Header/Host/Path) können später als **Konfiguration*- davor/danach geschaltet werden.

Tasks

1) TenantContext + Interface

- `TenantContext` (immutable Value Object)
    - Felder: `tenantId` (string), optional `siteIdentifier` (string), optional `baseHost` (string)
- `TenantResolverInterface`
    - `resolve(ServerRequestInterface $request): TenantContextResult`
    - Das Ergebnis enthält entweder TenantContext oder einen Fehlergrund (z. B. „site_not_found“)

#### 2) Default Resolver: SiteTenantResolver

- Implementiere `SiteTenantResolver`, der:
    - die **TYPO3 Site*- aus dem Request ableitet (Host/Pfad → Site)
    - `tenantId` setzt als:
        - bevorzugt: `siteIdentifier`
        - alternativ: konfigurierbar (z. B. base host, rootPageId)
- Falls keine Site ermittelbar ist:
    - **Reaktion**: `404` oder `400` oder was anderes ?

Hinweis: In TYPO3 ist „Site ermitteln“ in FE-Requests normal. Für API-Requests (Middleware) kann das je nach Stack
passieren, bevor/ob TYPO3 es schon gemacht hat. Deshalb: Resolver soll robust sein und nicht davon ausgehen, dass Site
bereits im Request liegt.

3) Resolver Chain vorbereiten (für später)

- `TenantResolverChain` (minimal)
    - nimmt eine Liste von Resolvern
    - nutzt den ersten, der einen Tenant liefern kann
- Für 1.0 aktiv: nur `SiteTenantResolver`
- Optional später: `HeaderTenantResolver`, `HostTenantResolver`, `PathTenantResolver`

4) Middleware Integration

- In der API Middleware:
    - `TenantContext` wird **immer*- in Request Attribut gesetzt (z. B. `api.tenant`)
    - Bei Fehler: Problem JSON mit konfigurierbarem Status (`404`/`400`)

Konfiguration (Minimal)

Beispiel (als PHP-Array oder YAML – je nach eurem Konfigstil):

```php
'tenant' => [
  'strategy' => 'site', // default
  'siteTenantIdSource' => 'identifier', // identifier|baseHost|rootPageId
  'onMissingTenant' => 404, // 404 oder 400
],
```

5) Bonuspunkte - Header Resolver (X-Tenant-Id)

Definition of Done

- Für jeden `/api/...` Request wird ein `TenantContext` gesetzt, standardmäßig aus der TYPO3 Site.
- Request ohne auflösbare Site/Tenant wird sauber abgelehnt (Problem JSON), Status konfigurierbar.
- `TenantContext` ist im Request verfügbar und kann von Security/Logging genutzt werden.
- Tests:
    - “Tenant wird gesetzt, wenn Site vorhanden”
    - “Fehler, wenn Site nicht bestimmbar” (404/400)
- Doku:
    - Erklärung: “Tenant = TYPO3 Site”
    - wie `tenantId` abgeleitet wird (identifier/host/rootPageId)
    - Hinweis auf spätere Erweiterung via Resolver Chain

## Optional (aber sehr sinnvoll) – Token-Bindung vorbereiten

Auch wenn die eigentliche Security erst Phase D ist:
Lege die Erwartung schon fest, dass Tokens später **immer*- mindestens an `tenantId + apiId` gebunden werden.

Das verhindert, dass ein Token aus Versehen zwischen Sites/API-Kontexten “funktioniert”, selbst wenn die Infrastruktur
ohnehin trennt.

---

Phase D – Security (Bearer Token + Scopes + LoginProvider)
Ziel: Sicherer Zugriff, erweiterbar.
DB / Domain

- Tabelle tx_apicore_token:
    - uid, pid
        - tenant_id (string)
        - api_id (string)
        - token_hash (string, z.B. sha256)
        - label (string)
        - scopes (json)
        - expires_at, revoked_at, last_used_at
        - optional: allowed_ips / rate_limit (später)
- optional Tabelle tx_apicore_tenant (oder Tenant aus externer Quelle)

Tasks

- LoginProviderInterface → authenticate(request): ?AuthContext
- BearerTokenProvider:
- liest Authorization: Bearer …
- hasht Token, lädt Record, prüft Tenant + API + Expiry + Revocation
- liefert Scopes + Token Identity
- Scope Enforcement pro Endpoint (Attribute #[RequireScopes([...])])
- Mechanismus zur Erweiterung:
- AdditionalLoginProvider kann User Identity liefern (z.B. “User Login”), Scopes erweitern
- Merge-Regeln (z.B. tokenScopes ∩ userScopes oder tokenScopes ∪ userScopes – als ADR)

Definition of Done

- Endpunkt kann Scopes verlangen, sonst 403
- Token kann pro Tenant + API getrennt sein
- Provider-Chain funktioniert (Bearer + optional UserLogin)

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
    - #[ApiEndpoint(path, methods, summary, tags, requestSchema?, responseSchema?, scopes?)]
    - #[ApiQueryParam(name, type, required, description)]
    - #[ApiPathParam(...)]
    - “Handler”-Konzept:
    - Controller-Methode erhält Request + Context, gibt DTO/Array oder Response zurück
    - Standard Response Envelope (optional):
    - {"data": ..., "meta": ...} (konfigurierbar)
    - Error Format vereinheitlichen (Problem JSON)

Definition of Done

- 2–3 Business-Endpunkte als Beispiel
- OpenAPI Infos aus Attributes ableitbar (Phase F)

---

Phase F – OpenAPI Generator + Swagger UI
Ziel: Doku ist first-class und releasefähig.
Tasks

- OpenAPI Spec Builder aus:
    - APIs Registry (apiId + version)
    - Routes + Attributes
    - Export:
    - /api/{apiId}/v{n}/docs.json
    - optional /docs.yaml
    - Viewer:
    - /api/{apiId}/v{n}/docs/ui (Swagger UI Assets)
    - CLI Command:
    - typo3 api:openapi:generate --api=public --version=1 --format=json --out=...

Definition of Done

- Spec enthält Security Schemes (Bearer)
- Spec enthält Endpunkte + Parameter + Response Codes
- Swagger UI zeigt alles korrekt an

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
- Konfiguration:
- global + pro Endpoint
- logRequest/logResponse/logHeaders/logBody
- redaction keys (token, authorization, password, secret)
- Korrelations-ID (Request ID)

Definition of Done

- Logs sind eindeutig (apiId, tenantId, endpointId, status, duration)
- Sensitive Daten werden maskiert

---

Phase H – TCA Mapper (Basis)
Ziel: TYPO3‑Records sauber mappen.
Tasks

- TcaMapper:
    - Whitelist-Felder
    - Relations (zunächst simpel: uid lists / resolved via Repository optional)
    - Field transformers (DateTime, int, bool)
    - DTO/Schema Integration: Mapper liefert arrays passend zu OpenAPI schema (wenn möglich)

Definition of Done

- Beispiel: Tabelle tt_content oder eigene Demo-Tabelle → JSON Ausgabe über Endpunkt

---

Phase I – Dokumentation & Open Source Readiness
Ziel: Releasefähig.
Tasks

- README: Quickstart, Installation, Konfiguration
    - docs/:
    - Tenants, APIs, Auth & Scopes, Writing Endpoints, OpenAPI, Logging, TCA Mapper
    - docs/adr/: Router Wahl, Scope Merge Regeln, Tenant Auflösung
    - “Example extension” oder examples/ Ordner

Definition of Done

- “Hello world API” in 10 Minuten nachvollziehbar
- Alle Konfigurationen dokumentiert
- Changelog / Versioning Policy (SemVer)
