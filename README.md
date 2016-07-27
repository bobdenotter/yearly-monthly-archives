Monthly and Yearly Archives
===========================

This extension allows your site to have 'monthly archives' or 'yearly
archives', which are used often on blog-like websites, or to organize a larger
archive of newsitems. This extension takes care of creating the 'list of links', as well as populating the actual archive pages.

![screen](https://cloud.githubusercontent.com/assets/1833361/8061500/65de807a-0ecb-11e5-9851-afb4b6772419.png)

To use, place the following tag in your template, where you'd like the list of links:

```
<h3>Monthly entry archives</h3>
<ul class='archive_list'>
    {{ monthly_archives('entries') }}
</ul>
```

or

```
<h3>Yearly news archives</h3>
<ul class='archive_list'>
    {{ yearly_archives('news', 'asc') }}
</ul>
```

The parameter passed, is the contenttype that's used for the archives. Be sure to pass this as a strings, _with_ the quotes. The second parameter can de `'asc'` or `'desc'`, and determines whether the results will be shown ascending (oldest first) or descending (newest first).

You can also pass the name of the column to sort on and/or the label to use. By this point, it becomes better to use named arguments for clarity.

```
<h3>Monthly calendar</h3>
<ul class='archive_list'>
    {{ monthly_archives(contenttypeslug = 'entries', order = 'asc', column = 'start_date') }}
</ul>


<h3>Monthly news archives</h3>
<ul class='archive_list'>
    {{ monthly_archives(contenttypeslug = 'entries', label = 'In the month %B of %Y.') }}
</ul>

```

Note: In most cases you do _not_ want to set `column` in the twig tag, but rather in `app/config/extensions/archives.bobdenotter.yml`, because that way it'll automatically work on the listing pages as well. 


