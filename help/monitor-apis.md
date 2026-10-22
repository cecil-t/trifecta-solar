The connections the Monitor page's collector uses, one per manufacturer. Admins only.

- Each card shows whether that manufacturer is set up, the last good scheduled pull, and any session (Enlighten for Enphase, Sunny Portal for SMA) with **Forget session** to sign in fresh.
- **Check** makes one call from the server and lists what came back. Nothing is saved.
- **SolarEdge:** the API credits left this cycle (the free tier has a monthly limit, so checks cost credits too).
- **Growatt:** **Check daily energy** reads this month's daily production for one site, as a check on that call.
- Keys and passwords live in the server's settings file, not in the tracker. A card that says "Not set up" names the settings to add.
