DNS Performance Monitoring runs on a cron:
# DNS monitoring daemon checker
* * * * * /home/pinescore.rcp-net.com/domains/speedtest.pinescore.rcp-net.com/public_html/check-dns-daemon.sh > /dev/null 2>&1

So when making changs to code, kill process and wait for new cron to begin, tail logs.

## Core Development Principles:
- Write simple, clear, and concise code
- Create self-documenting code
- Minimize comments
- Focus on modularity and cohesion
- Follow DRY (Don't Repeat Yourself) principle
- Limit file size to 400 lines
- Use constructor injection for dependencies

## Communication Protocol:
- Be direct and assertive
- Challenge ideas constructively
- Maximize value in interactions