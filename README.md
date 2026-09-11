# mr_president
Wordpress game where you become the president

## Campaign expansion (0.2.0 development)

Create a president with age, home state, alignment and three priorities. Govern through
separate House and Senate elections, seek reelection with approval strictly above 50%,
and finish with a one-term or eight-year legacy report. The campaign works without AI.

Run `php tests/run.php` for the dependency-free checks. See
[the engineering contract](docs/engineering-spec.md) for the campaign rules and save migration.
The browser preview in `tests/` uses mocked transport, not a live WordPress backend.

This development branch is not the installable ZIP. The `plugin` branch is the runtime-only
WordPress package; campaign changes must be reviewed and published there before downloading
an updated installation. Cabinet appointments, legislation drafting, judiciary gameplay and
sourced historical/current-event ingestion remain future work.
