<?php
/**
 * @copyright	Copyright (C) 2007 - 2014 Johan Janssens and Timble CVBA. (http://www.timble.net)
 * @license		Mozilla Public License, version 2.0
 * @link		http://github.com/joomlatools/joomla-console for the canonical source repository
 */

namespace Joomlatools\Console\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Joomlatools\Console\Joomla\Bootstrapper;

class FinderIndex extends SiteAbstract
{
    private $filters = array();

    private $app = '';

    private $time = null;

    private $qtime = null;

    protected function configure()
    {
        parent::configure();

        $this
            ->setName('finder:index')
            ->setDescription('Create finder indexes')
            ->addOption(
                'purge',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Whether the finder should purge existing results first?',
                false
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $this->app = Bootstrapper::getApplication($this->target_dir);
        // Load Library language
        $lang = \JFactory::getLanguage();

        // Try the finder_cli file in the current language (without allowing the loading of the file in the default language)
        $lang->load('finder_cli', JPATH_SITE, null, false, false)
        // Fallback to the finder_cli file in the default language
        || $lang->load('finder_cli', JPATH_SITE, null, true);

        $this->check($input, $output);

        $purge = $input->getOption('purge');

        if($purge)
        {
            $this->getFilters($input, $output);
            $this->purge($input, $output);
            $this->createIndexes($input, $output);
            $this->putFilters($input, $output);
        }
        else
        {
            $this->createIndexes($input, $output);
        }

    }

    public function check(InputInterface $input, OutputInterface $output)
    {
        if (!file_exists($this->target_dir)) {
            throw new \RuntimeException(sprintf('Site not found: %s', $this->site));
        }
    }

    public function getFilters(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(\JText::_('FINDER_CLI_SAVE_FILTERS'));

        // Get the taxonomy ids used by the filters.
        $db = \JFactory::getDbo();
        $query = $db->getQuery(true);
        $query
            ->select('filter_id, title, data')
            ->from($db->qn('#__finder_filters'));
        $filters = $db->setQuery($query)->loadObjectList();

        // Get the name of each taxonomy and the name of its parent.
        foreach ($filters as $filter)
        {
            // Skip empty filters.
            if ($filter->data == '')
            {
                continue;
            }

            // Get taxonomy records.
            $query = $db->getQuery(true);
            $query
                ->select('t.title, p.title AS parent')
                ->from($db->qn('#__finder_taxonomy') . ' AS t')
                ->leftjoin($db->qn('#__finder_taxonomy') . ' AS p ON p.id = t.parent_id')
                ->where($db->qn('t.id') . ' IN (' . $filter->data . ')');
            $taxonomies = $db->setQuery($query)->loadObjectList();

            // Construct a temporary data structure to hold the filter information.
            foreach ($taxonomies as $taxonomy)
            {
                $this->filters[$filter->filter_id][] = array(
                    'filter'	=> $filter->title,
                    'title'		=> $taxonomy->title,
                    'parent'	=> $taxonomy->parent,
                );
            }
        }

        return \JText::sprintf('FINDER_CLI_SAVE_FILTER_COMPLETED', count($filters));
    }

    //@todo duplication of code need to instatiate finderpurge::purge from here
    public function purge(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(\JText::_('FINDER_CLI_INDEX_PURGE'));

        require_once $this->app->getPath() . '/administrator/components/com_finder/models/index.php';
        $model = new \FinderModelIndex();

        // Attempt to purge the index.
        $return = $model->purge();

        // If unsuccessful then abort.
        if (!$return)
        {
            $message = \JText::_('FINDER_CLI_INDEX_PURGE_FAILED', $model->getError());

            throw new \RuntimeException($message);
        }

        $output->writeln(\JText::_('FINDER_CLI_INDEX_PURGE_SUCCESS'));
    }

    public function createIndexes(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(\JText::_('FINDER_CLI_INDEX_PURGE'));

        // Fool the system into thinking we are running as JSite with Smart Search as the active component.
        $_SERVER['HTTP_HOST'] = 'domain.com';
        \JFactory::getApplication('site');

        require_once $this->app->getPath() . '/administrator/components/com_finder/helpers/indexer/indexer.php';
        require_once $this->app->getPath() . '/administrator/components/com_finder/helpers/indexer/adapter.php';

        // Disable caching.
        $config = \JFactory::getConfig();
        $config->set('caching', 0);
        $config->set('cache_handler', 'file');

        // Reset the indexer state.
        \FinderIndexer::resetState();


        // Import the finder plugins.
        \JPluginHelper::importPlugin('finder');

        // Starting Indexer.
        $output->writeln(\JText::_('FINDER_CLI_STARTING_INDEXER'), true);

        // Trigger the onStartIndex event.
        \JEventDispatcher::getInstance()->trigger('onStartIndex');

        // Initialize the time value.
        $this->time = microtime(true);

        // Remove the script time limit.
        @set_time_limit(0);

        // Get the indexer state.
        $state = \FinderIndexer::getState();

        // Setting up plugins.
        $output->writeln(\JText::_('FINDER_CLI_SETTING_UP_PLUGINS'), true);

        // Trigger the onBeforeIndex event.
        \JEventDispatcher::getInstance()->trigger('onBeforeIndex');

        // Startup reporting.
        $output->writeln(\JText::sprintf('FINDER_CLI_SETUP_ITEMS', $state->totalItems, round(microtime(true) - $this->time, 3)), true);

        // Get the number of batches.
        $t = (int) $state->totalItems;
        $c = (int) ceil($t / $state->batchSize);
        $c = $c === 0 ? 1 : $c;

        try
        {
            // Process the batches.
            for ($i = 0; $i < $c; $i++)
            {
                // Set the batch start time.
                $this->qtime = microtime(true);

                // Reset the batch offset.
                $state->batchOffset = 0;

                // Trigger the onBuildIndex event.
                \JEventDispatcher::getInstance()->trigger('onBuildIndex');


                // Batch reporting.
                $output->writeln(\JText::sprintf('FINDER_CLI_BATCH_COMPLETE', ($i + 1), round(microtime(true) - $this->qtime, 3)), true);
            }
        }
        catch (Exception $e)
        {
            // Reset the indexer state.
            \FinderIndexer::resetState();

            throw new \RuntimeException($e->getMessage());
        }

        // Reset the indexer state.
        \FinderIndexer::resetState();
    }

    public function putFilters(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(\JText::_('FINDER_CLI_RESTORE_FILTERS'));

        $db = \JFactory::getDbo();

        // Use the temporary filter information to update the filter taxonomy ids.
        foreach ($this->filters as $filter_id => $filter)
        {
            $tids = array();

            foreach ($filter as $element)
            {
                // Look for the old taxonomy in the new taxonomy table.
                $query = $db->getQuery(true);
                $query
                    ->select('t.id')
                    ->from($db->qn('#__finder_taxonomy') . ' AS t')
                    ->leftjoin($db->qn('#__finder_taxonomy') . ' AS p ON p.id = t.parent_id')
                    ->where($db->qn('t.title') . ' = ' . $db->q($element['title']))
                    ->where($db->qn('p.title') . ' = ' . $db->q($element['parent']));
                $taxonomy = $db->setQuery($query)->loadResult();

                // If we found it then add it to the list.
                if ($taxonomy)
                {
                    $tids[] = $taxonomy;
                }
                else
                {
                    $this->out(\JText::sprintf('FINDER_CLI_FILTER_RESTORE_WARNING', $element['parent'], $element['title'], $element['filter']));
                }
            }

            // Construct a comma-separated string from the taxonomy ids.
            $taxonomyIds = empty($tids) ? '' : implode(',', $tids);

            // Update the filter with the new taxonomy ids.
            $query = $db->getQuery(true);
            $query
                ->update($db->qn('#__finder_filters'))
                ->set($db->qn('data') . ' = ' . $db->q($taxonomyIds))
                ->where($db->qn('filter_id') . ' = ' . (int) $filter_id);
            $db->setQuery($query)->execute();
        }

        $output->writeln(\JText::sprintf('FINDER_CLI_RESTORE_FILTER_COMPLETED', count($this->filters)));
    }
}
