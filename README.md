# sentrix_mail_safety
Drupal module used to send out emails by drush 


# Git tags 
Every time you do an update eg v1.0.1

`git tag -l`

`git tag v1.0.0`

`git push origin v1.0.0`

---
`ddev composer require adaptive-web/sentrix_mail_safety:1.0.0`

---
# Site notes: 

Sits in "repositories": 

When "repositories" is named use it 

```
    "sentrix_mail_safety":{
        "type": "vcs",
        "url": "git@github.com:adaptive-web/sentrix_mail_safety.git"
    },
```
When its not named 

```
    {
        "type": "vcs",
        "url": "git@github.com:adaptive-web/sentrix_mail_safety.git"
    },
```

Under "scripts" section add the following:
`"link-sentrix-mail-safety": "if [ -d sentrix_mail_safety ]; then echo \"Linking sentrix_mail_safety...\"; rm -rf web/modules/contrib/sentrix_mail_safety && ln -sfn ../../../sentrix_mail_safety web/modules/contrib/sentrix_mail_safety; fi",
`

`ddev composer require adaptive-web/sentrix_mail_safety:1.0.0`

Next you do an update run `ddev composer require adaptive-web/sentrix_mail_safety:1.0.1`


Respect what scripts you have + add this:

pre-update-cmd, post-update-cmd, post-install-cmd, post-autoload-dump

```
"pre-update-cmd": [
"DrupalComposerManaged\\ComposerScripts::preUpdate",
"@link-sentrix-mail-safety"
],
"post-update-cmd": [
"@link-sentrix-mail-safety"
],
"post-install-cmd": [
"@link-sentrix-mail-safety"
],
"post-autoload-dump": [
"@link-sentrix-mail-safety"
]
```

