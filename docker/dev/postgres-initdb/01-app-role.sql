-- Dev fixture only: mirrors a real project's topology of a superuser
-- (DB_ROOT_USERNAME, here the image's default "postgres" role) plus a
-- separate, unprivileged app role that owns the actual database.
CREATE ROLE app WITH LOGIN PASSWORD 'secret';
ALTER DATABASE app OWNER TO app;
GRANT ALL PRIVILEGES ON DATABASE app TO app;
