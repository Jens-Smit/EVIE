# Filesystem Protection

## Bedrohung

Ein Tool-Aufruf mit einem Pfad, der auf sensitive System-Dateien zeigt
(`/etc/passwd`, `/proc/1/environ`, `/var/run/docker.sock`), könnte
System-Zugang exfiltrieren oder Container-Escape ermöglichen.

## Mitigation

`SecurityGuard::isPathSafe(string $path): bool` blockt folgende Pfade:

| Pfad | Grund |
|------|--------|
| `/etc` | System-Konfiguration (passwd, shadow) |
| `/root` | Root-Home-Verzeichnis (.ssh, .bashrc) |
| `/home` | User-Home-Verzeichnisse |
| `/var` | System-Daten (logs, run, lib) |
| `/usr` | System-Binaries |
| `/bin`, `/sbin` | Ausführbare Dateien |
| `/proc` | Prozess-Informationen (environ, cmdline) |
| `/sys` | Kernel-Subsysteme |
| `/dev` | Geräte-Dateien (sda, null, zero) |
| `/boot` | Bootloader-Konfiguration |

## Integration

`SecurityGuard::decide()` extrahiert String-Argumente aus dem `ToolCall` und
prüft jede Pfad-ähnliche Zeichenfolge (`/` oder `./` beginnend). Bei einem
Verstoß → `PolicyDecision::Deny`.

Erlaubt sind nur Pfade in der Sandbox (`/tmp/`, projektspezifische Upload-Dirs).

## Tests

`SecurityHardeningTest`:
- `testFilesystemBlocksEtcPasswd`
- `testFilesystemBlocksDockerSocket` (`/var/run/docker.sock`)
- `testFilesystemBlocksRootDir`
- `testFilesystemBlocksProc/Sys/Dev`
- `testFilesystemBlocksVarRun`
- `testFilesystemAllowsSandboxTmpPath`
