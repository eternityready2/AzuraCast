# AI DJ default content library

`default_content.json.gz` is the AI DJ content library every station gets
(`App\Service\AiDjDefaultContent`, loaded by `AiDjDefaultContentTask`): song
intro and post-song templates, Bible verses, jokes, encouragement,
inspiration, testimonies and stories. A category is filled once, only while
the station has nothing of its own in it.

Format: `{"version": 1, "types": {"<type>": [{"content": "...",
"reference": "...", "is_global": false}]}}`; `reference` is optional and
`is_global` defaults to true.

Bible verses are the World English Bible (public domain), from
https://ebible.org/Scriptures/eng-web_vpl.zip. Psalm titles printed before
verse 1 and the Hebrew letter heading of Psalm 119 are left out so the DJ
reads only the verse; Romans 16:25-27 are WEB's Romans 14:24-26.
