<?php

namespace CampaignChain\Report\AnalyticsCtaTrackingBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\HttpFoundation\Response;

class AnalyticsCtaTrackingPerLocationController extends Controller
{
    const GRAPH_TRUNCATE_NAME = 22;

    public function indexAction(Request $request)
    {
        $campaign = array();
        $form = $this->createFormBuilder($campaign)
            ->add('campaign', 'entity', array(
                'label' => 'Campaign',
                'class' => 'CampaignChainCoreBundle:Campaign',
                // Only display campaigns for selection that actually have report data
                'query_builder' => function(EntityRepository $er) {
                        return $er->createQueryBuilder('campaign')
                            ->select('cpgn')
                            ->from('CampaignChain\CoreBundle\Entity\Campaign', 'cpgn')
                            ->from('CampaignChain\CoreBundle\Entity\CTA', 'cta')
                            ->from('CampaignChain\CoreBundle\Entity\Operation', 'o')
                            ->from('CampaignChain\CoreBundle\Entity\Activity', 'a')
                            ->from('CampaignChain\CoreBundle\Entity\ReportCTA', 'r')
                            ->where('cta.operation = o.id')
                            ->andWhere('o.activity = a.id')
                            ->andWhere('a.campaign = cpgn.id')
                            ->andWhere('r.campaign = cpgn.id')
                            ->orderBy('campaign.startDate', 'ASC');
                    },
                'choice_label' => 'name',
                'placeholder' => 'Select a Campaign',
                'empty_data' => null,
            ))
            ->add('location', 'text', array(
                'label' => 'Location',
                'mapped' => false,
                'attr' => array('placeholder' => 'Select a location')
            ))
            ->getForm();

        $form->handleRequest($request);

        if ($form->isValid()) {
            return $this->redirect(
                $this->generateUrl(
                    'campaignchain_analytics_cta_tracking_per_location_read',
                    array(
                        'campaignId' => $form->getData()['campaign']->getId(),
                        'locationId' => $form->get('location')->getData(),
                    )
                )
            );
        }

        $tplVars = array(
            'page_title' => 'Customer Journey Per Location',
            'form' => $form->createView(),
            'dependent_select_parent' => 'form_campaign',
            'dependent_select_child' => 'form_location',
            'dependent_select_route' => 'campaignchain_core_report_list_cta_locations_per_campaign_api',
        );

        return $this->render(
            'CampaignChainCoreBundle:Base:new_dependent_select.html.twig',
            $tplVars);
    }

    public function readAction(Request $request, $campaignId, $locationId){
        $campaignService = $this->get('campaignchain.core.campaign');
        $campaign = $campaignService->getCampaign($campaignId);

        return $this->render(
            'CampaignChainReportAnalyticsCtaTrackingBundle::index.html.twig',
            array(
                'page_title' => 'Customer Journey Per Location',
                'campaign' => $campaign,
                'api_data' => $this->generateUrl(
                        'campaignchain_analytics_cta_tracking_per_location_data_api',
                        array(
                            'campaignId' => $campaignId,
                            'locationId' => $locationId,
                        )
                    ),
            ));
    }

    public function apiDataAction(Request $request, $campaignId, $locationId){
        $locationService = $this->container->get('campaignchain.core.location');
        $location = $locationService->getLocation($locationId);

        $chartData = array();

        $chartData['nodes'][] = array(
            'name' => 'location_'.$location->getId(),
            'display_name' => $location->getName(),
            'type' => 'location',
            'tpl_medium' => $locationService->tplTeaser(
                    $location,
                    array(
                        'truncate_middle' => self::GRAPH_TRUNCATE_NAME,
                    )
                ),
            'direction' => 'outbound',
        );

        $repository = $this->getDoctrine()
            ->getRepository('CampaignChainCoreBundle:ReportCTA');

        // Get outbound nodes and links excluding the CTA's Location.
        $qb = $repository->createQueryBuilder('r');
        $qb->select('r')
            ->where('r.campaign = :campaignId')
            ->andWhere('r.sourceLocation = :locationId')
            ->andWhere('r.targetLocation != :locationId')
            ->groupBy('r.targetLocation')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('locationId', $locationId);
        $query = $qb->getQuery();
        $CTAs = $query->getResult();

        // TODO: Change the below once an outbound link can point to another CTA (e.g. form).
        foreach($CTAs as $CTA){
            // If target location is null, then this is an external location,
            // i.e. a location in a channel that is not connected with CampaignChain.
            if(!$CTA->getTargetLocation()){
                $nodeName = $CTA->getTargetUrl();
                $nodeDisplayName = $nodeName;
                $nodeType = 'campaignchain-external';
                $tplTeaser = '<i class="fa fa-external-link fa-lg"></i>&nbsp;<a href="'.$CTA->getTargetUrl().'">'.$CTA->getTargetUrl().'</a>';
            } else {
                $nodeName = 'location_'.$CTA->getTargetLocation()->getId();
                $nodeDisplayName = $CTA->getTargetName();
                $classParts = explode('\\',get_class($CTA->getTargetLocation()));
                $nodeType = 'campaignchain-'.strtolower(end($classParts));
                $tplTeaser = $locationService->tplTeaser(
                    $CTA->getTargetLocation(),
                    array(
                        'truncate_middle' => self::GRAPH_TRUNCATE_NAME,
                    )
                );
            }
            $chartData['nodes'][] = array(
                'name' => $nodeName,
                'display_name' => $nodeDisplayName,
                'type' => $nodeType,
                'tpl_medium' => $tplTeaser,
                'direction' => 'outbound',
            );

            // Get number of outbound clicks.
            $outboundClicks = $this->countOutboundClicks($campaignId, $locationId, $CTA->getTargetUrl());

            // Skip if source equals target.
            if($CTA->getTargetLocation() && (
                    $locationId != $CTA->getTargetLocation()->getId()
                )
            ){
                $chartData['links'][] = array(
                    'source' => 'location_'.$locationId,
                    'target' => 'location_'.$CTA->getTargetLocation()->getId(),
                    'value' => $outboundClicks,
                );
            }
            if(!$CTA->getTargetLocation()){
                $chartData['links'][] = array(
                    'source' => 'location_'.$locationId,
                    'target' => $CTA->getTargetUrl(),
                    'value' => $outboundClicks,
                );
            }
        }

        // Get inbound nodes and links.
        $qb = $repository->createQueryBuilder('r');
        $qb->select('r')
            ->where('r.campaign = :campaignId')
            ->andWhere('r.sourceLocation = :locationId')
            ->andWhere('r.sourceLocation = r.targetLocation')
            ->groupBy('r.CTA')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('locationId', $locationId);
        $query = $qb->getQuery();
        $CTAs = $query->getResult();

        $activityService = $this->container->get('campaignchain.core.activity');

        foreach($CTAs as $CTA){
            // Get number of inbound clicks.
            $inboundClicks = $this->countInboundClicks($campaignId, $locationId, $CTA->getCta());

            $chartData['nodes'][] = array(
                'name' => 'activity_'.$CTA->getActivity()->getId(),
                'display_name' => $CTA->getSourceName(),
                'type' => 'campaignchain-activity',
                'tpl_medium' => $activityService->tplTeaser($CTA->getActivity(),
                        array(
                            'show_trigger' => true,
                            'truncate_middle' => self::GRAPH_TRUNCATE_NAME,
                        )
                    ),
                'direction' => 'inbound',
            );

            $chartData['links'][] = array(
                'source' => 'activity_'.$CTA->getActivity()->getId(),
                'target' => 'location_'.$locationId,
                'value' => $inboundClicks,
            );
        }

        $serializer = $this->get('campaignchain.core.serializer.default');

        return new Response($serializer->serialize($chartData, 'json'));
    }

    public function countOutboundClicks($campaignId, $locationId, $targetUrl)
    {
        $repository = $this->getDoctrine()
            ->getRepository('CampaignChainCoreBundle:ReportCTA');
        $qb = $repository->createQueryBuilder('r');
        $qb->select('COUNT(r)')
            ->where('r.campaign = :campaignId')
            ->andWhere('r.sourceLocation = :locationId')
            ->andWhere('r.targetUrl = :targetUrl')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('locationId', $locationId)
            ->setParameter('targetUrl', $targetUrl);
        return $qb->getQuery()->getSingleScalarResult();
    }

    public function countInboundClicks($campaignId, $locationId, $cta)
    {
        $repository = $this->getDoctrine()
            ->getRepository('CampaignChainCoreBundle:ReportCTA');
        $qb = $repository->createQueryBuilder('r');
        $qb->select('COUNT(r)')
            ->where('r.campaign = :campaignId')
            ->andWhere('r.targetLocation = :locationId OR r.sourceLocation = :locationId OR r.CTA = :cta')
            ->andWhere('r.CTA = :cta')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('locationId', $locationId)
            ->setParameter('cta', $cta);
        return $qb->getQuery()->getSingleScalarResult();
    }
}
