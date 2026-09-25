# Daymark

A local-first daily tracker built with PHP, SQLite, vanilla JavaScript, browser notifications, and optional Gemini analysis.

## Run locally

1. Make sure PHP has `pdo_sqlite` enabled. Gemini analysis also needs `curl`.
2. Optional: set your Gemini key in the environment. In PowerShell:

   ```powershell
   $env:GEMINI_API_KEY = "your-gemini-api-key"
   ```

3. Start the development server from this folder:

   ```powershell
   php -S localhost:8000
   ```

4. Open http://localhost:8000.

The SQLite database is created automatically as `tracker.sqlite` and stays local to this folder. Browser reminders require notification permission and are scheduled for 09:00 in the browser's local time.
