#!/usr/bin/env bash
set -euo pipefail

required_extensions=(
  bcmath
  ctype
  curl
  dom
  ftp
  gd
  hash
  iconv
  intl
  mbstring
  openssl
  pdo_mysql
  simplexml
  soap
  sodium
  xsl
  zip
)

missing=()

for extension in "${required_extensions[@]}"; do
  if ! php -m | awk '{ print tolower($0) }' | grep -qx "${extension}"; then
    missing+=("${extension}")
  fi
done

if [ ${#missing[@]} -eq 0 ]; then
  echo "All required PHP extensions are available for Mage-OS."
  exit 0
fi

echo "Missing PHP extensions for Mage-OS:"
for extension in "${missing[@]}"; do
  echo " - ${extension}"
done

echo
echo "Ubuntu/Debian quick fix (PHP 8.2):"
echo "  sudo apt-get update && sudo apt-get install -y php8.2-mysql php8.2-soap php8.2-intl php8.2-gd php8.2-xml php8.2-zip php8.2-bcmath php8.2-curl php8.2-mbstring"

exit 1
