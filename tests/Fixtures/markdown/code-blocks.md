# Deployment

<a name="server-requirements"></a>
## Server Requirements

Your server will need a few packages installed before you deploy. You may install them with your package manager of choice.

```shell
# Install the required PHP extensions
sudo apt install php8.4-pgsql php8.4-redis

## This line is a comment, not a heading
```

The commands above assume a Debian based distribution.

<a name="optimization"></a>
## Optimization

When deploying to production, cache your configuration and routes so the framework can boot without reading every file.

~~~bash
### Not a heading either
php artisan optimize
~~~

Remember to clear the cache whenever you change a configuration value.
