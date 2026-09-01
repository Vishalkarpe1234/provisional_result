#!/usr/bin/env bash
# Starts the marksheet generator with PHP's upload limits raised.
# The built-in server ignores .htaccess and .user.ini, so the limits have to
# be passed on the command line instead.

cd "$(dirname "$0")" || exit 1

if ! command -v php >/dev/null 2>&1; then
    echo "PHP was not found on your PATH."
    exit 1
fi

echo "Starting the marksheet generator at http://localhost:8000"
echo "Press Ctrl+C to stop it."

exec php -d upload_max_filesize=512M \
         -d post_max_size=512M \
         -d memory_limit=512M \
         -d max_execution_time=300 \
         -d max_input_time=300 \
         -S localhost:8000
