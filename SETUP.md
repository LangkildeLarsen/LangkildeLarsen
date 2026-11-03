# langkildelarsen.dk

One pager med CV + privat admin til lifehacks. Ren PHP, ingen database. Data (profil, lifehacks, adgangskode) gemmes i `data/` på serveren og billeder i `uploads/` – de ligger ikke i GitHub og bliver aldrig overskrevet af en deploy.

## Engangsopsætning

1. **Opret et privat repo** på GitHub, fx `langkildelarsen`.
2. **Find SFTP-oplysninger** i one.com-kontrolpanelet under SSH & SFTP: host, brugernavn og adgangskode, samt mappen hvor hjemmesidens filer ligger.
3. **Tilføj secrets** i repoet under Settings → Secrets and variables → Actions → New repository secret:

   | Navn | Værdi |
   |---|---|
   | `SFTP_HOST` | SFTP-host fra one.com |
   | `SFTP_USER` | SFTP-brugernavn |
   | `SFTP_PASSWORD` | SFTP-adgangskode |
   | `SFTP_PATH` | mappen hvor sitet skal ligge (web-roden) |

4. Når koden er pushet til `main`, uploader GitHub Actions automatisk. Status ses under fanen Actions.
5. Gå til `langkildelarsen.dk/admin/` og vælg din adgangskode (første besøg).
6. Udfyld Profil & CV og upload dit billede.

## Brug

- Forside: `/`
- Admin: `/admin/` (også linket nederst på forsiden)
- Lifehacks er kun synlige, når du er logget ind.
