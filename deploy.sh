            git status
            git stash
            git pull origin master
            /usr/local/bin/ea-php84 $(which composer) install --no-interaction --prefer-dist --optimize-autoloader
            /usr/local/bin/ea-php84 artisan optimize:clear
            chmod -R 775 storage
            chmod -R 775 bootstrap/cache
            chmod 777 -R storage/*
            /usr/local/bin/ea-php84 artisan migrate --force
            /usr/local/bin/ea-php84 artisan storage:link
       
            
            
            sudo supervisord -c /etc/supervisord.conf

            
            sudo supervisorctl restart queue-worker:*
            /usr/local/bin/ea-php84 artisan run:sql
            /usr/local/bin/ea-php84 artisan view:cache
            /usr/local/bin/ea-php84 artisan run:odoo-connection
