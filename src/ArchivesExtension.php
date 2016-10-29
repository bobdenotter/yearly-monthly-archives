<?php

namespace Bolt\Extension\Bobdenotter\Archives;

use Bolt\Asset\Widget\Widget;
use Bolt\Exception\InvalidRepositoryException;
use Bolt\Extension\SimpleExtension;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Silex\Application;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Response;

class ArchivesExtension extends SimpleExtension
{
    /**
     * @param string $type
     * @param string $contentTypeName
     * @param string $order
     * @param string $label
     * @param string $column
     * @param string $header
     *
     * @return string|\Twig_Markup
     */
    public function widget($type, $contentTypeName, $order, $label, $column, $header)
    {
        if ($type === 'monthly') {
            if (empty($label)) {
                $label = '%B %Y';
            }
            $html = $this->monthlyArchives($contentTypeName, $order, $label, $column);
        } else {
            if (empty($label)) {
                $label = '%Y';
            }
            $html = $this->yearlyArchives($contentTypeName, $order, $label, $column);
        }

        if (!empty($header)) {
            $html = sprintf("<h5>%s</h5>\n%s", $header, $html);
        }

        return $html;
    }

    /**
     * Yearly archives Twig function.
     *
     * @param string $contentTypeName
     * @param string $order
     * @param string $label
     * @param string $column
     *
     * @return \Twig_Markup
     */
    public function yearlyArchives($contentTypeName, $order = '', $label = '%Y', $column = '')
    {
        return $this->archiveHelper($contentTypeName, $order, 5, $label, $column);
    }

    /**
     * Monthly archives Twig function.
     *
     * @param string $contentTypeName
     * @param string $order
     * @param string $label
     * @param string $column
     *
     * @return \Twig_Markup
     */
    public function monthlyArchives($contentTypeName, $order = '', $label = '%B %Y', $column = '')
    {
        return $this->archiveHelper($contentTypeName, $order, 8, $label, $column);
    }

    /**
     * Archive listing route.
     *
     * @param Application $app
     * @param string      $contenttypeslug
     * @param integer     $period
     *
     * @return string
     */
    public function archiveList(Application $app, $contenttypeslug, $period)
    {
        $config = $this->getConfig();
        $contentTypeName = $contenttypeslug;
        // Scrub, scrub.
        $period = preg_replace('/[^0-9-]+/', '', $period);
        if (strlen($period) !== 4 && strlen($period) !== 7) {
            return 'Wrong period parameter';
        }

        try {
            $repo = $app['storage']->getRepository($contentTypeName);
        } catch (InvalidRepositoryException $e) {
            return 'Not a valid ContentType';
        }
        $contentType = $app['storage']->getContenttype($contentTypeName);

        // If the contenttype is 'viewless', don't show the record page.
        if (isset($contentType['viewless']) && $contentType['viewless'] === true) {
            $app->abort(Response::HTTP_NOT_FOUND, "Page $contentTypeName not found.");

            return null;
        }

        if (!empty($config['columns'][$contentTypeName])) {
            $column = $config['columns'][$contentTypeName];
        } elseif (empty($column)) {
            $column = 'datepublish';
        }

        // Use a custom query to fetch the ids.
        $query = $repo->createQueryBuilder();
        $query
            ->where($query->expr()->like($column, $query->expr()->literal($period . '%')));
        ;
        //$records = $query->execute()->fetchAll() ?: [];
        $temp_ids = $query->execute()->fetchAll() ?: [];
        $ids = [];
        foreach ($temp_ids as $temp_id) {
            $ids[] = $temp_id['id'];
        }

        // Fetch the records, based on the ids we gathered earlier. Doing it this way
        // allows us to keep the sorting intact, as well as skip unpublished records.
        $records = $app['storage']->getContent($contentType['slug'], ['id' => implode(' || ', $ids)]);
        
        if (count($records) === 1) {
            $records = [$records];
        }

        // Get the correct template
        if (!empty($config['template'])) {
            $template = $config['template'];
        } else {
            $template = $app['templatechooser']->listing($contentType);
        }

        // Make sure we can also access it as {{ pages }} for pages, etc. We set
        // these in the global scope, so that they're also available in menu's
        // and templates rendered by extensions.
        $context = [
            'records'        => $records,
            $contentTypeName => $records,
            'contenttype'    => $contentType['name'],
        ];
        $html = $app['render']->render($template, [], $context);

        return $html;
    }

    /**
     * {@inheritdoc}
     */
    protected function registerFrontendRoutes(ControllerCollection $collection)
    {
        $config = $this->getConfig();

        $prefix = !empty($config['prefix']) ? $config['prefix'] : 'archives';

        $collection
            ->get('/' . $prefix . '/{contenttypeslug}/{period}', [$this, 'archiveList'])
            ->bind('archiveList')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function registerAssets()
    {
        $widgets = [];
        $config = $this->getConfig();

        foreach ((array) $config['widgets'] as $contentTypeName => $widget) {
            if (!isset($widget['label'])) {
                $widget['label'] = '';
            }
            if (!isset($widget['column'])) {
                $widget['column'] = '';
            }
            if (!isset($widget['order'])) {
                $widget['order'] = '';
            }
            if (!isset($widget['header'])) {
                $widget['header'] = '';
            }

            $widgetObj = new Widget();
            $widgetObj
                ->setZone('frontend')
                ->setLocation($widget['location'])
                ->setCallback([$this, 'widget'])
                ->setCacheDuration(0)
                ->setCallbackArguments([
                    'type'            => $widget['type'],
                    'contenttypeslug' => $contentTypeName,
                    'order'           => $widget['order'],
                    'label'           => $widget['label'],
                    'column'          => $widget['column'],
                    'header'          => $widget['header'],
                ]);

            if (isset($widget['priority'])) {
                $widgetObj->setPriority($widget['priority']);
            }

            $widgets[] = $widgetObj;
        }

        return $widgets;
    }
    /**
     * {@inheritdoc}
     */
    protected function registerTwigFunctions()
    {
        return [
            'yearly_archives'  => ['yearlyArchives',  ['is_safe' => ['html']]],
            'monthly_archives' => ['monthlyArchives', ['is_safe' => ['html']]],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig()
    {
        return [
            'prefix'  => 'archives',
            'ucwords' => true,
            'columns' => [],
            'widgets' => [],
        ];
    }

    /**
     * @param string $contentTypeName
     * @param string $order
     * @param int    $length
     * @param string $label
     * @param string $column
     *
     * @return string|\Twig_Markup
     */
    private function archiveHelper($contentTypeName, $order, $length, $label, $column)
    {
        $app = $this->getContainer();
        $config = $this->getConfig();
        $output = '';

        try {
            $repo = $app['storage']->getRepository($contentTypeName);
        } catch (InvalidRepositoryException $e) {
            return 'Not a valid ContentType';
        }

        if (strtolower($order) !== 'asc') {
            $order = 'desc';
        }

        if (!empty($config['columns'][$contentTypeName])) {
            $column = $config['columns'][$contentTypeName];
        } elseif (empty($column)) {
            $column = 'datepublish';
        }

        $index = 0;
        // MySql uses 1-indexed strings instead of 0-indexed strings. Make adjustments for their wonky implementation of SUBSTR(...)
        if ($app['db']->getDatabasePlatform() instanceof MySqlPlatform) {
            $index = 1;
            $length -= 1;
        }

        $query = $repo->createQueryBuilder();
        $query
            ->select("SUBSTR($column, $index, $length) as year")
            ->groupBy('year')
            ->orderBy('year', $order)
        ;
        $rows = $query->execute()->fetchAll();
        foreach ($rows as $row) {
            // Don't print out links for records without dates.
            if (in_array($row['year'], ['0000', '0000-00', null])) {
                continue;
            }

            $link = $app['url_generator']->generate(
                'archiveList',
                ['contenttypeslug' => $contentTypeName, 'period' => $row['year']]
            );
            $period = strftime($label, strtotime($row['year'] . '-01'));
            if ($config['ucwords'] === true) {
                $period = ucwords($period);
            }

            $output .= sprintf('<li><a href="%s">%s</a></li>%s', $link, $period, "\n");
        }

        return new \Twig_Markup($output, 'UTF-8');
    }
}
