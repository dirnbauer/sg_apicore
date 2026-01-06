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
    - Das Ergebnis enthält entweder TenantContext oder einen Fehlergrund (z. B. „site_not_found“)

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

Tasks

- LoginProviderInterface → authenticate(request): ?AuthContext
- BearerTokenProvider:
    - liest Authorization: Bearer …
    - hasht Token, lädt Record, prüft Tenant + API + Expiry + Revocation
    - liefert Scopes + Token Identity
    - Scope Enforcement pro Endpoint (Attribute #[RequireScopes([...])])
- Mechanismus zur Erweiterung:
    - AdditionalLoginProvider kann User Identity liefern (z. B. “User Login”), Scopes erweitern
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
- Funktionsweise und Mechanimus in der README erklären

---

Phase D-II – Extended Security Definition (Private APIs)
Ziel: Private API, Proper JWT Auth. Opaque Tokens

**Ziel**
Auth in `sg_apicore` so strukturieren, dass:

- **Public/simple private APIs*- primär über **Opaque Tokens (harte Tokens)*- laufen
- **User-Level APIs*- standardmäßig ebenfalls **Opaque Access Tokens*- nutzen (DB-basiert, revokable), aber **optional
  JWT Access Tokens + Refresh Token Flow*- unterstützen.

Generell:

- Bisherige Realisierung ist dann partiell nicht mehr gültig und zu ändern und aufzuräumen.
- token wieder zu token_hash SHA256 ändern, aber in der TCA-DEscription eine Hilfsanleitung und gegebenfalls Online-Tool
  anbieten. Aus Sicherheitsgründen.
- Test-Controller erweitern

Scope der Phase (was zu bauen ist)

1) Auth-Konfiguration pro API (und optional pro Version)

- API-Security erweitern, z. B.:
    - `auth.mode`: `token | user`
    - `auth.providers`: Liste aktiver Provider (z. B. `bearer_opaque`, optional `jwt_access`)
- Default:
    - `public`: keine Auth-Pflicht
    - `token`: Opaque Bearer Token Pflicht
    - `user`: Opaque Access Token Pflicht (JWT optional aktivierbar)

**DoD:*- API kann pro `apiId` (und optional Version) definieren, ob Auth required ist und welche Provider aktiv sind.

2) Opaque Bearer Token als Standard (für “token” und “user”)

- Token ist ein zufälliger String (Bearer), **DB speichert nur Hash*- (z. B. SHA-256).
- Scopes kommen aus DB.
- `last_used_at` wird aktualisiert.

**DoD:*- Opaque Tokens funktionieren tenant+api gebunden, revokable, performant (kein Iterieren über alle Tokens).

3) User-Level: Opaque Access Token + Refresh Token (Default)

- Für User-Level wird ein **Access Token*- (kurzlebig) + **Refresh Token*- (langlebig) eingeführt.
- Zum Erhalt des Refresh Tokens Tokens muss der User erstmal authentifiziert werden mit User/Password wie in TYPO3
  vorgesehen.
- Refresh Endpoint (z. B. `/auth/refresh`) tauscht Refresh Token gegen neuen Access Token.
- Refresh Tokens sind:
    - ebenfalls opaque + gehasht in DB
    - revokable
    - optional “rotation” (Refresh Token wird bei Nutzung ersetzt)

**DoD:*- Refresh Flow existiert und ist minimal dokumentiert (wie anfordern, wie refreshen, welche TTL).

4) JWT Access Token für User-Level

- JWT ist **aktiv**, wenn erkannt.
- JWT wird **für Access Tokens*- genutzt, Refresh bleibt opaque (DB).
- Minimal JWT Best Practices:
    - nur erlaubter `alg` (Whitelist)
    - `hash_equals` für Signatur
    - `exp` Pflicht, `jti` Pflicht
    - `tenantId` + `apiId` in Claims, müssen zum Request Context passen
- JWT Access Token werden erzeugt beim Login/Refresh (statt opaque Access Token), wenn aktiviert.
- bitte aktuelle JWT-Implementierung prüfen, da die nicht so sonderlich korrekt ist.

**DoD:*- Umschaltbar: User-Level Access Token ist entweder opaque oder JWT, Refresh bleibt identisch.

5) Provider-Struktur (einheitlich für alle Modi)

- Login Provider Chain bleibt simpel:
    - `BearerOpaqueTokenProvider` (standard)
    - optional `JwtAccessTokenProvider` (nur user-mode + config)
- Provider liefert immer `AuthContext` (mit optional `userId`).

**DoD:*- Endpoints arbeiten immer gegen `AuthContext` + Scopes, unabhängig vom Auth-Modus.

Tests (minimal)

- Public mode: Endpunkt ohne Authorization → 200
- Token mode: ohne Bearer → 401, mit valid token → 200
- User mode opaque: Access token gültig → 200, expired/revoked → 401
- Refresh: gültiger refresh → neuer access → 200
- JWT optional: falscher alg/signature/exp/tenant/api mismatch → 401

Dokumentation (minimal)

- Auth Modes (public/token/user) public = more or less our current implementation if no scope is given
- Opaque Token Handling (Hashing, Revocation, Scopes)
- User-Level Flow (Access/Refresh)
- JWT optional aktivieren (Konfig + Claim-Anforderungen)

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

Goal:

- Vorbereitung für den openAPI-Generator (Textbeschreibungen, etc per Attributen)
- Ich denke, dass hier einiges bereits vorab gelöst worden ist.

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

Phase J – Backend-Modul: Verwaltung von APIs, Tokens, Providern & Endpunkten (kurz)

**Ziel**
TYPO3 Backend-Modul für `sg_apicore`, um **APIs**, **Tenants**, **Tokens (Access/Refresh)**, **Provider-Konfiguration*-
sowie **Endpoint-Übersicht*- komfortabel zu verwalten – ohne manuelle DB-Eingriffe.

Scope (MVP)

1) Backend-Modul “API Core”

- Modul-Entry unter System/Tools (oder eigener Main-Module-Punkt)
- Tabs/Views:
    1. **APIs & Versionen**
    2. **Tokens**
    3. **Provider**
    4. **Endpoints (Read-only)**

**DoD:*- Modul sichtbar, sauber berechtigt (Admin/Backend-Group), TYPO3 12/13 kompatibel.

2) Token Management (MVP)

- Liste + Filter: `apiId`, `tenantId`, `type` (access/refresh), `active/revoked/expired`
- Aktionen:
    - **Create Token*- (generiert plaintext einmalig, speichert nur Hash)
    - **Revoke Token**
    - **Set/Update Scopes**
    - **Set Expiry**
- Anzeige:
    - `label`, `scopes`, `expires_at`, `last_used_at`, `revoked_at`

**DoD:*- Token können im Backend erzeugt werden, plaintext wird nur einmal angezeigt, Auth funktioniert damit.

3) Provider-Konfiguration (MVP)

- Anzeige aktiver Provider pro `apiId` (z. B. `bearer_opaque`, optional `jwt_access`)
- Konfig-Editor (minimal): enable/disable Provider, JWT Secret/Algo (falls aktiv)

**DoD:*- Provider per API umschaltbar, Konfig wird gelesen und wirkt zur Laufzeit.

4) Endpoint Overview (Read-only, MVP)

- Liste aller registrierten Endpoints (aus Attributes/Registry):
    - `method`, `path`, `summary`, `requiredScopes`, `apiId`, `version`
- Optional: Link zu OpenAPI/Swagger UI

**DoD:*- Übersicht entspricht dem Runtime-Routing (keine Fake-Daten).

Non-Goals (für diese Phase)

- Kein vollwertiger API-Designer
- Keine CRUD-Generatoren
- Keine komplexe Rechteverwaltung (nur Backend-Berechtigung + später optional)

Tests & Doku (minimal)

- Smoke-Test: Modul lädt, Token create/revoke läuft.
- Doku: “Token im Backend anlegen und im Client nutzen” + Screenshots optional.

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

---

### Phase K – sg_rest Drop-In Replacement & Migrationspfad (kurz)

**Ziel**
`sg_apicore` so erweitern, dass bestehende Installationen mit `sg_rest` **schrittweise*- migriert werden können –
idealerweise als **Drop-In Replacement*- für einen definierten Teilumfang (v1), ohne die alte Extension sofort zu
entfernen.

## Scope (MVP / v1 Drop-In)

### 1) Kompatibilitätsmodus aktivierbar

- Konfig-Flag: `compat.sg_rest.enabled = true`
- Optional: `compat.sg_rest.basePath = /rest` (oder was bei sg_rest genutzt wurde)

**DoD:*- Bei aktivem Flag werden Requests unter dem alten sg_rest Pfad von `sg_apicore` übernommen.

---

### 2) Routing-/Endpoint-Kompatibilität

- Mapping alter sg_rest URLs → neue Endpoint-Definitionen

    - 1:1 Route-Mapping (Preferred)
    - oder Rewrite/Alias-Mechanismus (Fallback)
- Unterstützung für alte Versionierung/Prefix-Logik (sofern vorhanden)

**DoD:*- Ein definierter Satz “Legacy Routes” liefert identische Responses (Status + JSON Struktur) wie vorher.

---

### 3) Auth-Kompatibilität (minimal)

- Support für bisherigen sg_rest Auth-Modus:

    - Opaque Token / API-Key (wie in sg_rest)
    - Optional: JWT, falls sg_rest das genutzt hat
- Optional: Import/Bridge für bestehende Token-Daten (wenn DB-Schema abweicht)

**DoD:*- Bestehende Clients können weiterhin authentifizieren, ohne Token-Rollout (oder mit minimaler Umstellung).

---

### 4) Response-Kompatibilität

- Response Envelope kompatibel schaltbar:

    - Felder/Keys (z. B. `data`, `meta`, `errors`)
    - Pagination-Format wie bisher
- Error-Format kompatibel schaltbar (sofern sg_rest abweicht)

**DoD:*- Clients/Integrationen brechen nicht wegen anderer JSON-Struktur.

---

### 5) Logging & Behavior Parity (nur wo relevant)

- Optional: gleiche Log-Felder wie sg_rest (Request/Response/Params)
- Performance: keine “Legacy-Shims”, die pro Request massiv overhead erzeugen

**DoD:*- Legacy Verhalten ist ausreichend gleich, ohne neue Bottlenecks.

---

## Vorgehen / Migrationsstrategie

1. **Inventory**: Liste aller sg_rest Endpunkte + Auth + Response-Formate
2. **Priorisieren**: Top 20 Endpunkte zuerst (nach Traffic/Business)
3. **Dual-Run**: sg_rest bleibt installiert, `sg_apicore` übernimmt nur den Legacy-Prefix (oder nur einzelne Routen)
4. **Cutover**: wenn parity erreicht, sg_rest Routen deaktivieren / entfernen

---

## Non-Goals (für v1)

- 100% sg_rest Feature-Clone
- automatische Migration aller Custom-Endpunkte ohne Mapping
- vollständige Kompatibilität, wenn sg_rest “Magic”/Typoscript-Routing hatte (nur explizit gemappte Fälle)

---

## Tests & DoD global

- Golden-Master Tests: legacy request → response JSON exakt gleich (oder definierte tolerierte Unterschiede)
- Dokumentation: “Welche Endpunkte sind drop-in-kompatibel, welche nicht” + Migrations-Checkliste

---

**Hinweis (wichtig):*- Ob “Drop-In” wirklich möglich ist, hängt fast komplett davon ab, wie stark `sg_rest` bei euch *
*Response-Formate, Routing-Magic, Token-Handling*- und **TCA/Entity-Mapping*- verzahnt hat. Für einen Teilumfang ist es
meist gut machbar; als vollständiger Ersatz eher nur mit klarer Endpunktliste und Golden-Master-Tests.

Wenn du mir sagst, wie sg_rest aktuell routet (Prefix, Versioning, Response Envelope, Auth), kann ich Phase Y noch enger
auf eure Realität zuschneiden (inkl. “Minimum viable drop-in” Liste).
