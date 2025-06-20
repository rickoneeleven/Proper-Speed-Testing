**CRITICAL** When the user types "fetch", download intructions from https://notes.pinescore.com/note/note_683df4954cc0f4.04393849 and replace the top contents of your existing CLAUDE.md with the below, including this message.

## Core Development Principles

Adherence to these principles is mandatory for all code modifications:

**Simplicity, Clarity & Conciseness:** Write only necessary code.
**Self-Documenting Code:** Rely on clear, descriptive naming (variables, functions, classes, modules) and logical structure. Purpose should be evident without comments.
**Minimal Comments:** Avoid comments. Remove existing comments. Code must be the source of clarity.
**Modularity & Cohesion:** Aim for highly cohesive components with clear responsibilities and loose coupling. Controllers/Coordinators avoid unrelated logic.
**DRY (Don't Repeat Yourself):** Extract and reuse common logic patterns.
**Dependency Management:** Prefer constructor injection. Avoid direct creation of complex services within consumers.
**Maximum 400 line files:** Keep files modular (excluding external libraries etc), if files are greater than 400 lines, break down the logic following the principles above. Never append text to any file over 400 lines without the users express permission. 
**Count lines:** Once completing a todo list, always use the find command to ensure no file you have worked on has a line count greater than 400 lines. If it has, reiterate following the core principles above. 
**Troubleshooting:** For web app, client side related issues, feel free to use console debug output and ask the user to fetch the console messages from developer tools, they are familiar with this process.

## Communication Protocol

**Be direct and assertive** - Push back when user may be going down wrong path, prioritize value over politeness
**Challenge when needed** - Don't just agree - point out potential issues or better alternatives
**Maximize value** - Every interaction should provide genuine utility, not just validation

## Communication Protocol
**Commands:** 
fetch - As stated above, download this file from https://notes.pinescore.com/note/note_683df4954cc0f4.04393849 and update the top half of your contents.
trim - Your CLAUDE.md file as this point has likely become bloated. Review the whole file looking for duplication of data/notes. Remove duplication, update file to be concise.


====-
## Project Specific Intructions Below

DNS Performance Monitoring runs on a cron:
# DNS monitoring daemon checker
* * * * * /home/pinescore.rcp-net.com/domains/speedtest.pinescore.rcp-net.com/public_html/check-dns-daemon.sh > /dev/null 2>&1

So when making changs to code, kill process and wait for new cron to begin, tail logs.