<?php

namespace Bolt\Extension\Bobdenotter\Archives;

use Bolt\Application;
use Bolt\BaseExtension;
use Doctrine\DBAL\Platforms\MySqlPlatform;

class Extension extends BaseExtension
{

    /**
     * Set up routing and Twig functions..
     */
    public function initialize() {

        $this->addTwigFunction('yearly_archives', 'yearlyArchives');
        $this->addTwigFunction('monthly_archives', 'monthlyArchives');

        $prefix = !empty($this->config['prefix']) ? $this->config['prefix'] : 'archives';

        $this->app->get("/" . $prefix . "/{contenttypeslug}/{period}", array($this, 'archiveList'))->bind('archiveList');

    }

    /**
     * Extension name
     */
    public function getName()
    {
        return "Yearly & Monthly Archives";
    }

    public function yearlyArchives($contenttypeslug, $order = '', $label = '%Y', $column = '') {
        return $this->archiveHelper($contenttypeslug, $order, 5, $label, $column);
    }


    public function monthlyArchives($contenttypeslug, $order = '', $label = '%B %Y', $column = '') {
        return $this->archiveHelper($contenttypeslug, $order, 8, $label, $column);
    }

    private function archiveHelper($contenttypeslug, $order, $length, $label, $column)
    {
        $contenttype = $this->app['storage']->getContenttype($contenttypeslug);

        if (empty($contenttype)) {
            return 'Not a valid contenttype';
        }

        $tablename = $this->app['storage']->getContenttypeTablename($contenttype);

        if (strtolower($order) != 'asc') {
            $order = 'desc';
        }

        if (!empty($this->config['columns'][$contenttypeslug])) {
            $column = $this->config['columns'][$contenttypeslug];
        } else if (empty($column)) {
            $column = 'datepublish';
        }

        $index = 0;
        // MySql uses 1-indexed strings instead of 0-indexed strings. Make adjustments for their wonky implementation of SUBSTR(...)
        if ($this->app['db']->getDatabasePlatform() instanceof MySqlPlatform) {
            $index = 1;
            $length -= 1;
        }

        $query = "SELECT SUBSTR($column, $index, $length) AS year FROM $tablename GROUP BY year ORDER BY year $order;";
        $statement = $this->app['db']->executeQuery($query);
        $rows = $statement->fetchAll();

        $output = '';

        foreach($rows as $row) {
            
            // Don't print out links for records without dates.
            if (in_array($row['year'], ['0000', '0000-00', null])) {
                continue;
            }
            
            $link = $this->app['url_generator']->generate(
                    'archiveList',
                    array('contenttypeslug' => $contenttype['slug'], 'period' => $row['year'])
                );

            $period = strftime($label, strtotime($row['year'].'-01'));

            if ($this->config['ucwords'] == true) {
                $period = ucwords($period);
            }

            $output .= sprintf(
                "<li><a href='%s'>%s</a></li>\n",
                $link,
                $period
                );
        }

        return new \Twig_Markup($output, 'UTF-8');

    }


    public function archiveList($contenttypeslug, $period)
    {
        // Scrub, scrub.
        $period = preg_replace('/[^0-9-]+/', '', $period);

        if (strlen($period) != 4 && strlen($period) != 7) {
            return 'Wrong period parameter';
        }

        $contenttype = $this->app['storage']->getContenttype($contenttypeslug);

        if (empty($contenttype)) {
            return 'Not a valid contenttype';
        }

        // If the contenttype is 'viewless', don't show the record page.
        if (isset($contenttype['viewless']) && $contenttype['viewless'] === true) {
            $this->abort(Response::HTTP_NOT_FOUND, "Page $contenttypeslug not found.");
            return null;
        }

        $tablename = $this->app['storage']->getContenttypeTablename($contenttype);

        if (!empty($this->config['columns'][$contenttypeslug])) {
            $column = $this->config['columns'][$contenttypeslug];
        } else if (empty($column)) {
            $column = 'datepublish';
        }

        // Use a custom query to fetch the ids.
        $query = "SELECT id FROM $tablename WHERE $column like '$period%';";
        $statement = $this->app['db']->executeQuery($query);
        $temp_ids = $statement->fetchAll();

        $ids = [];

        foreach($temp_ids as $temp_id) {
            $ids[] = $temp_id['id'];
        }

        // Fetch the records, based on the ids we gathered earlier. Doing it this way
        // allows us to keep the sorting intact, as well as skip unpublished records.
        $records = $this->app['storage']->getContent(
                $contenttype['slug'],
                array('id' => implode(' || ', $ids))
            );

        if (!is_array($records)) {
            $records = array($records);
        }

        // Get the correct template
        if (!empty($this->config['template'])) {
            $template = $this->config['template'];
        } else {
            $template = $this->app['templatechooser']->listing($contenttype);
        }

        // Make sure we can also access it as {{ pages }} for pages, etc. We set these in the global scope,
        // So that they're also available in menu's and templates rendered by extensions.
        $globals = [
            'records'            => $records,
            $contenttype['slug'] => $records,
            'contenttype'        => $contenttype['name']
        ];

        return $this->app['render']->render($template, [], $globals);

    }

}
