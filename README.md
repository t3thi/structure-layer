# Structure Layer PoC (`t3thi/structure-layer`)

Proof of concept for the TYPO3 Translation Handling Initiative: the default
language (`sys_language_uid = 0`) becomes a hidden structural layer, and
every real language – including today's default – is a connected
translation. Output is driven by the fallback chain alone.

Not for production. See `docs/plan.md` and `docs/findings.md`.

```bash
ddev start && ddev setup && ddev test
ddev demo   # https://structure-layer.ddev.site/typo3/
```
