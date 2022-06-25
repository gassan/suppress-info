# suppress-info

Composer plugin can suppress annoying info messages from repositories

## Installation
```
composer global require gassan/suppress-info
composer global config extra.suppress-info <regexp>
# or
composer global config --json extra.suppress-info '{"repo_url1": <regexp>, "repo_url2": [<regexp1>, <regexp2>]}'
```

If the repo_url is not specified, it will be treated as https://repo.packagist.org
