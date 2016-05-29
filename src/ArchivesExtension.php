<?php

namespace Bolt\Extension\Bobdenotter\Archives;

use Bolt\Extension\SimpleExtension;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Silex\ControllerCollection;

use Bolt\Asset\Widget\Widget;

class ArchivesExtension extends SimpleExtension
{

    /**
     * Set up routing and Twig functions..
     */
    protected function registerTwigFunctions()
    {
        return [
            'yearly_archives' => 'yearlyArchives',
            'monthly_archives' => 'monthlyArchives',
        ];
    }

    protected function registerFrontendRoutes(ControllerCollection $collection)
    {
        $config = $this->getConfig();

        $prefix = !empty($config['prefix']) ? $config['prefix'] : 'archives';

        $collection->get("/" . $prefix . "/{contenttypeslug}/{period}", array($this, 'archiveList'))->bind('archiveList');
    }

    protected function registerAssets()
    {
        $widgets = [];
        $config = $this->getConfig();

        foreach ((array) $config['widgets'] as $contenttypeslug => $widget) {

            if (!isset($widget['label'])) {
                $widget['label'] = '';
            }
            if (!isset($widget['column'])) {
                $widget['column'] = '';
            }
            if (!isset($widget['order'])) {
                $widget['order'] = '';
            }

            $widgetObj = new Widget();
            $widgetObj
                ->setZone('frontend')
                ->setLocation($widget['location'])
                ->setCallback([$this, 'widget'])
                ->setCacheDuration(300)
                ->setCallbackArguments([
                    'type' => $widget['type'],
                    'contenttypeslug' => $contenttypeslug,
                    'order' => $widget['order'],
                    'label' => $widget['label'],
                    'column' => $widget['column']]
                );

            if (isset($widget['priority'])) {
                $widgetObj->setPriority($widget['priority']);
            }

            $widgets[] = $widgetObj;
        }

        return $widgets;
    }

    public function widget($type, $contenttypeslug, $order, $label, $column)
    {
        if ($type == 'monthly') {
            if (empty($label)) {
                $label = '%B %Y';
            }
            $html = $this->monthlyArchives($contenttypeslug, $order, $label, $column);
        } else {
            if (empty($label)) {
                $label = '%Y';
            }
            $html = $this->yearlyArchives($contenttypeslug, $order, $label, $column);
        }

        return $html;
    }


    public function yearlyArchives($contenttypeslug, $order = '', $label = '%Y', $column = '')
    {
        return $this->archiveHelper($contenttypeslug, $order, 5, $label, $column);
    }

    public function monthlyArchives($contenttypeslug, $order = '', $label = '%B %Y', $column = '')
    {
        return $this->archiveHelper($contenttypeslug, $order, 8, $label, $column);
    }

    private function archiveHelper($contenttypeslug, $order, $length, $label, $column)
    {
        $app = $this->getContainer();
        $config = $this->getConfig();

        $contenttype = $app['storage']->getContenttype($contenttypeslug);

        if (empty($contenttype)) {
            return 'Not a valid contenttype';
        }

        $tablename = $app['storage']->getContenttypeTablename($contenttype);

        if (strtolower($order) != 'asc') {
            $order = 'desc';
        }

        if (!empty($config['columns'][$contenttypeslug])) {
            $column = $config['columns'][$contenttypeslug];
        } else if (empty($column)) {
            $column = 'datepublish';
        }

        $index = 0;
        // MySql uses 1-indexed strings instead of 0-indexed strings. Make adjustments for their wonky implementation of SUBSTR(...)
        if ($app['db']->getDatabasePlatform() instanceof MySqlPlatform) {
            $index = 1;
            $length -= 1;
        }

        $query = "SELECT SUBSTR($column, $index, $length) AS year FROM $tablename GROUP BY year ORDER BY year $order;";
        $statement = $app['db']->executeQuery($query);
        $rows = $statement->fetchAll();

        $output = '';

        foreach($rows as $row) {

            // Don't print out links for records without dates.
            if (in_array($row['year'], ['0000', '0000-00', null])) {
                continue;
            }

            $link = $app['url_generator']->generate(
                    'archiveList',
                    array('contenttypeslug' => $contenttype['slug'], 'period' => $row['year'])
                );

            $period = strftime($label, strtotime($row['year'].'-01'));

            if ($config['ucwords'] == true) {
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
        $app = $this->getContainer();

        // Scrub, scrub.
        $period = preg_replace('/[^0-9-]+/', '', $period);

        if (strlen($period) != 4 && strlen($period) != 7) {
            return 'Wrong period parameter';
        }

        $contenttype = $app['storage']->getContenttype($contenttypeslug);

        if (empty($contenttype)) {
            return 'Not a valid contenttype';
        }

        // If the contenttype is 'viewless', don't show the record page.
        if (isset($contenttype['viewless']) && $contenttype['viewless'] === true) {
            $this->abort(Response::HTTP_NOT_FOUND, "Page $contenttypeslug not found.");
            return null;
        }

        $tablename = $app['storage']->getContenttypeTablename($contenttype);

        if (!empty($config['columns'][$contenttypeslug])) {
            $column = $config['columns'][$contenttypeslug];
        } else if (empty($column)) {
            $column = 'datepublish';
        }

        // Use a custom query to fetch the ids.
        $query = "SELECT id FROM $tablename WHERE $column like '$period%';";
        $statement = $app['db']->executeQuery($query);
        $temp_ids = $statement->fetchAll();

        $ids = [];

        foreach($temp_ids as $temp_id) {
            $ids[] = $temp_id['id'];
        }

        // Fetch the records, based on the ids we gathered earlier. Doing it this way
        // allows us to keep the sorting intact, as well as skip unpublished records.
        $records = $app['storage']->getContent(
                $contenttype['slug'],
                array('id' => implode(' || ', $ids))
            );

        if (!is_array($records)) {
            $records = array($records);
        }

        // Get the correct template
        if (!empty($config['template'])) {
            $template = $config['template'];
        } else {
            $template = $app['templatechooser']->listing($contenttype);
        }

        // Make sure we can also access it as {{ pages }} for pages, etc. We set these in the global scope,
        // So that they're also available in menu's and templates rendered by extensions.
        $globals = [
            'records'            => $records,
            $contenttype['slug'] => $records,
            'contenttype'        => $contenttype['name']
        ];

        return $app['render']->render($template, [], $globals);

    }

}
