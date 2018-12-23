# Webmentions to Comments
Turn webmentions into blog comments.

Incoming webmentions only. All webmentions undergo basic validation and are then dumped into a database table. The content of that table is parsed once per hour, asynchronously. Source URLs of which the markup contains so-called microformats—Bridgy, most blogs—undergo some additional, rudimentary parsing.
