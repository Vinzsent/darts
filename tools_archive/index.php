<?php
// tools_archive/ holds one-shot dev/debug scripts moved out of the web root.
// They are NOT part of the app. Do not link to them. Do not deploy this folder.
http_response_code(403);
exit('Forbidden: dev tools archive.');
