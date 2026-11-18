# ADR 0002 — HTTP client adaptations to the AE contract

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The reference `JsonApiClient` sends `x-request-id` and `recouv.application.id`, builds only GET and POST-with-body
requests and expects a JSON object on success. The AE contract (api-reference §1, §2):

- declares **no request header** (§1);
- identifies the account in the **query** (`siret`, plus `dateNaissance` on 030 / 031) of 020, 030, 031, 040, 050,
  060 and 075, in the **body** of 070 and in the **path** of 071 (`/sepa/mandats/{siret}/{rum}`);
- uses `POST /mandats` with query only, `DELETE /mandats`, `DELETE /sepa/mandats/{siret}/{rum}?dateEffet=…`, and
  `POST` with query **and** JSON body (040, 050, 060);
- answers 030 / 031 with an empty 200 (§2.2, §2.3), 071 with **204** (§2.8), 075 with a top-level JSON **array**
  (§2.9), the others with a JSON object;
- documents 400, 401, 403, 500 for every operation, 404 and 409 for the SEPA operations only, 429 in the PDF only
  (§1, §1.1, §3.4); 401 / 403 / 500 of the AE operations come without body, SEPA errors as a list (§1.3).

## Decision

- `Http\ApiRequest` supports `get(op, path, query)`, `post(op, path, query, ?body)` and `delete(op, path, query)`.
  Query values and path segments are encoded with RFC 3986 (`rawurlencode`); `{siret}` and `{rum}` are inserted as
  encoded segments, never concatenated raw.
- `JsonApiClient` treats an empty 2xx body as "no payload" (`ApiResponse::isEmpty()`). Expected success shapes, checked
  by the response mappers:
  - 030, 031: 200 with an empty body or `{}`; 071: 204 without body (an empty 200 is accepted too, as a 2xx with
    nothing to read); any other content is `UnexpectedUrssafResponse`;
  - 075: a JSON list (`Payload::isList()` / `entries()`), possibly empty;
  - every other operation: a JSON object (wrapper tolerance: [ADR 0006](0006-two-contracts-one-model.md)).
- Headers: `Authorization: Bearer`, `User-Agent` and `Accept: application/json` always; `Content-Type:
  application/json` only when the request has a body. `ApiConfiguration` exposes an optional request-id header (name
  only, value generated per attempt by the `IdGenerator` port) and an optional application-id header (name + value),
  both **off by default** (bundle `headers.request_id`, `headers.application_id`).
- Status mapping of an API call:
  - 2xx: decoded by the operation mapper;
  - 400: `UrssafRequestRejected` with the parsed errors and their `RejectionKind` ([ADR 0005](0005-error-classification.md));
  - 401: the token is invalidated and the call replayed **once** (a 401 proves the call was not processed), then
    `UrssafAuthenticationFailed`;
  - 403: `UrssafAuthenticationFailed`;
  - 404 on 070 / 071 / 075: `UrssafRequestRejected` of kind `not_found` (body parsed leniently, code *not published*);
    the package does not turn a 404 of 075 into an empty list (§2.9, §5);
  - 409 on 070: `UrssafRequestRejected` of kind `sepa_mandate_already_exists`, with or without body;
  - 404 / 409 on any other operation, and any other 4xx: `UnexpectedUrssafResponse`;
  - 429: always `UrssafUnavailable` (retryable, never an unknown outcome), with its `Retry-After` (delta-seconds) when
    present;
  - 5xx: `UrssafUnavailable` or `OutcomeUnknown` according to the operation retry class
    ([ADR 0003](0003-retry-and-unknown-outcome.md)).
  Error bodies of 404 / 409 / 429 / 5xx are parsed leniently (`ErrorPayloadParser::parseBody()`, object or list) and
  kept on the exception (`ERR_TECHNIQUE`).
- Token endpoint: 400 / 401 / 403 are `UrssafAuthenticationFailed`; 429 and every 5xx are `UrssafUnavailable`
  (`whileAuthenticating`, never an unknown outcome: the API call was not sent). The token URL is
  `Environment::baseUri()` (or the overridden `base_uri`) + `UrssafEndpoints::TOKEN_PATH` (api-reference §1); the
  sandbox token host is *not published* (the sandbox Swagger declares the production `tokenUrl`, §5) and is settled
  by the contract test.
- Transport failures are sanitised: exceptions carry an internal `TransportError` holding only the class name of the
  PSR-18 exception, never its message, which usually embeds the URI and therefore the SIRET, the birth date (030 /
  031) or the RUM (071) ([ADR 0010](0010-personal-data.md)). Neither the query string nor the path is logged.

## Consequences

- The client stays a port-free internal class, as in the reference; only its request shapes, success shapes and header
  policy differ.
- If URSSAF asks for correlation headers, enabling them is configuration only.
- The contract test settles the 071 status (204 vs 200) and the 075 empty case; the mappers already accept both
  readings that the specification leaves open.
