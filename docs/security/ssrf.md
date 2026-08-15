# SSRF Protection

## Bedrohung

Ein Tool-Aufruf mit einer URL, die auf interne Ressourcen zeigt
(localhost, private IPs, AWS metadata endpoint), könnte interne Dienste
exfiltrieren oder angreifen.

## Mitigation

`SecurityGuard::isUrlSafe(string $url): bool` prüft:

1. **Geblockte URLs:** `http://localhost`, `https://localhost`,
   `http://127.0.0.1`, `https://127.0.0.1`
2. **Geblockte Hosts:** `localhost`, `127.0.0.1`, `192.168.`, `10.`, `172.16.`,
   `169.254.`, `0.0.0.0`, `::1`, `fe80::`, `fc00::`
3. **Private IP-Prüfung:** `filter_var()` + `ip2long()` Range-Check für
   10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 127.0.0.1, 0.0.0.0

## Integration

`SecurityGuard::decide()` extrahiert String-Argumente aus dem `ToolCall` und
prüft jede URL-ähnliche Zeichenfolge. Bei einem Verstoß → `PolicyDecision::Deny`.

## Tests

`SecurityHardeningTest`:
- `testSsrfBlocksLoopback127`
- `testSsrfBlocksLocalhost`
- `testSsrfBlocksLinkLocal169_254_169_254` (AWS metadata)
- `testSsrfBlocksPrivateRange10/192_168/172_16`
- `testSsrfBlocksWildcardBind0_0_0_0`
- `testSsrfBlocksIpv6Loopback/LinkLocal/UniqueLocal`
- `testSsrfBlocksInArrayArguments`
