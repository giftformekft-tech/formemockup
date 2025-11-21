/**
 * Pattern Showcase Block
 *
 * Gutenberg block for displaying pattern showcases
 *
 * @package MockupGenerator
 * @since 1.3.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl, Placeholder, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Register Pattern Showcase Block
 */
registerBlockType('mockup-generator/pattern-showcase', {
    edit: Edit,
    save: () => null, // Dynamic block - rendered via PHP
});

/**
 * Block Edit Component
 */
function Edit({ attributes, setAttributes }) {
    const { showcaseId, layout, columns } = attributes;
    const blockProps = useBlockProps();

    const [showcases, setShowcases] = useState([]);
    const [loading, setLoading] = useState(true);
    const [preview, setPreview] = useState(null);

    // Load showcases on mount
    useEffect(() => {
        loadShowcases();
    }, []);

    // Load preview when showcase changes
    useEffect(() => {
        if (showcaseId) {
            loadPreview(showcaseId);
        }
    }, [showcaseId]);

    /**
     * Load available showcases
     */
    const loadShowcases = async () => {
        setLoading(true);

        try {
            const response = await apiFetch({
                path: '/mockup-generator/v1/pattern-showcases',
            });

            if (response && response.showcases) {
                setShowcases(response.showcases);
            }
        } catch (error) {
            console.error('Error loading showcases:', error);
        } finally {
            setLoading(false);
        }
    };

    /**
     * Load showcase preview
     */
    const loadPreview = async (id) => {
        try {
            const response = await apiFetch({
                path: `/mockup-generator/v1/pattern-showcases/${id}`,
            });

            if (response && response.showcase) {
                setPreview(response.showcase);
            }
        } catch (error) {
            console.error('Error loading preview:', error);
        }
    };

    /**
     * Get showcase options for select control
     */
    const getShowcaseOptions = () => {
        const options = [
            { label: __('Select a showcase...', 'mockup-generator'), value: '' }
        ];

        Object.values(showcases).forEach((showcase) => {
            options.push({
                label: showcase.name,
                value: showcase.id
            });
        });

        return options;
    };

    /**
     * Get effective layout (from block or showcase default)
     */
    const getEffectiveLayout = () => {
        if (layout) return layout;
        if (preview && preview.layout) return preview.layout;
        return 'carousel';
    };

    /**
     * Get effective columns (from block or showcase default)
     */
    const getEffectiveColumns = () => {
        if (columns > 0) return columns;
        if (preview && preview.columns) return preview.columns;
        return 4;
    };

    /**
     * Render placeholder (no showcase selected)
     */
    if (!showcaseId || loading) {
        return (
            <div {...blockProps}>
                <Placeholder
                    icon="images-alt2"
                    label={__('Pattern Showcase', 'mockup-generator')}
                    instructions={__('Select a pattern showcase to display', 'mockup-generator')}
                >
                    {loading ? (
                        <Spinner />
                    ) : (
                        <SelectControl
                            label={__('Choose Showcase', 'mockup-generator')}
                            value={showcaseId}
                            options={getShowcaseOptions()}
                            onChange={(value) => setAttributes({ showcaseId: value })}
                        />
                    )}
                </Placeholder>

                <InspectorControls>
                    <PanelBody title={__('Showcase Settings', 'mockup-generator')}>
                        <SelectControl
                            label={__('Select Showcase', 'mockup-generator')}
                            value={showcaseId}
                            options={getShowcaseOptions()}
                            onChange={(value) => setAttributes({ showcaseId: value })}
                        />
                    </PanelBody>
                </InspectorControls>
            </div>
        );
    }

    /**
     * Render preview
     */
    return (
        <>
            <div {...blockProps}>
                <div className="mg-pattern-showcase-editor-preview">
                    <div className="mg-preview-header">
                        <h4>{__('Pattern Showcase:', 'mockup-generator')} {preview?.name}</h4>
                        <span className="mg-preview-badge">
                            {getEffectiveLayout() === 'carousel' ? __('Carousel', 'mockup-generator') : __('Grid', 'mockup-generator')}
                        </span>
                    </div>

                    {preview && preview.mockups && Object.keys(preview.mockups).length > 0 ? (
                        <div className="mg-preview-grid">
                            {Object.entries(preview.mockups).slice(0, 6).map(([key, attachmentId]) => (
                                <div key={key} className="mg-preview-item">
                                    <img
                                        src={preview.mockup_urls?.[key] || ''}
                                        alt={key}
                                    />
                                </div>
                            ))}
                            {Object.keys(preview.mockups).length > 6 && (
                                <div className="mg-preview-more">
                                    +{Object.keys(preview.mockups).length - 6} {__('more', 'mockup-generator')}
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="mg-preview-empty">
                            {__('No mockups generated yet. Generate mockups in the Pattern Showcases admin page.', 'mockup-generator')}
                        </div>
                    )}
                </div>
            </div>

            <InspectorControls>
                <PanelBody title={__('Showcase Settings', 'mockup-generator')}>
                    <SelectControl
                        label={__('Select Showcase', 'mockup-generator')}
                        value={showcaseId}
                        options={getShowcaseOptions()}
                        onChange={(value) => setAttributes({ showcaseId: value })}
                    />

                    <hr />

                    <SelectControl
                        label={__('Layout Override', 'mockup-generator')}
                        value={layout}
                        options={[
                            { label: __('Use showcase default', 'mockup-generator'), value: '' },
                            { label: __('Carousel', 'mockup-generator'), value: 'carousel' },
                            { label: __('Grid', 'mockup-generator'), value: 'grid' }
                        ]}
                        onChange={(value) => setAttributes({ layout: value })}
                        help={__('Override the default layout for this block instance', 'mockup-generator')}
                    />

                    {(layout === 'grid' || (getEffectiveLayout() === 'grid' && !layout)) && (
                        <RangeControl
                            label={__('Grid Columns', 'mockup-generator')}
                            value={columns || getEffectiveColumns()}
                            onChange={(value) => setAttributes({ columns: value })}
                            min={2}
                            max={6}
                            help={__('Number of columns in grid layout', 'mockup-generator')}
                        />
                    )}
                </PanelBody>

                <PanelBody title={__('Showcase Info', 'mockup-generator')} initialOpen={false}>
                    {preview && (
                        <div className="mg-showcase-info">
                            <p>
                                <strong>{__('Products:', 'mockup-generator')}</strong>{' '}
                                {preview.product_types?.length || 0}
                            </p>
                            <p>
                                <strong>{__('Mockups:', 'mockup-generator')}</strong>{' '}
                                {Object.keys(preview.mockups || {}).length}
                            </p>
                            <p>
                                <strong>{__('Default Layout:', 'mockup-generator')}</strong>{' '}
                                {preview.layout}
                            </p>
                        </div>
                    )}
                </PanelBody>
            </InspectorControls>
        </>
    );
}
