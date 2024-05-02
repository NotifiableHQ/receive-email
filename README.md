# Receive Email

Let your Laravel app receive emails.

## Installation
```bash
composer require notifiableapp/receive-email
```

## Forge Deployment
1. Add this to your recipes, you can name it `Install Mailparse`. Make sure the user is `root`.
```bash
apt-get update
apt-get install -y php-cli php-mailparse
```
2. If you already have an existing server, run this recipe on that server. 
Otherwise, create a new server and make sure to select this recipe as a `Post-Provision Recipe`. 
You'll have to show `Advance Settings` to select this.

3. Once you have the server ready, open up `Port 25`, add your site, and deploy your Laravel app.

4. SSH into your Forge server and go to your site directory. Then run the setup command as a `super user`:
```bash
sudo php artisan notifiable:setup-postfix your-domain.com
```

## Credits
The solutions in this package are inspired by the following projects:
- [Mailcare](https://gitlab.com/mailcare/mailcare)
- [Laravel Mailbox](https://github.com/beyondcode/laravel-mailbox)