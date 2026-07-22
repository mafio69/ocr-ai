FROM alpine:3.24

RUN apk add --no-cache squid \
    && mkdir -p /var/log/squid /var/cache/squid \
    && chown -R squid:squid /var/log/squid /var/cache/squid

COPY squid.conf /etc/squid/squid.conf

USER squid
EXPOSE 3128

ENTRYPOINT ["squid", "-N", "-f", "/etc/squid/squid.conf"]