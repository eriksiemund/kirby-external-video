<template>
    <div class="es-external-video-block">
        <div class="es-external-video-container" :class="{ 'is-hidden': !content.url }">
            <k-video-file-preview
                :details="details"
                :url="content.url"
                class="k-file-preview"
                :video-attrs="{ crossOrigin: 'anonymous' }"
            />
    
            <div class="es-external-video-poster-wrapper">
                <k-button
                class="es-generate-video-poster-button"
                v-bind="$props"
                icon="image"
                variant="filled"
                @click="handleGenerateVideoPoster"
                >Generate Video Poster</k-button>
        
                <k-block-figure>
                    <k-image-frame
                        :cover="false"
                        back="black"
                        :ratio="1/1"
                        :src="posterUrl"
                    />
                </k-block-figure>
            </div>
        </div>
        <div class="es-external-video-container" :class="{ 'is-hidden': content.url }">
            <div class="es-warning">
                <p>External Video URL missing.</p>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    props: {
    },
    computed: {
        pageId() {
            return this.endpoints.model.replace(/^\/pages\//, '')
        },
        blockId() {
            return this.id
        },
        fieldName() {
            return this.endpoints.field.match(/\/fields\/([^/]+)/)?.[1]
        },
        posterUrl() {
            return this.content.poster[0]?.url || null
        }
    },
    methods: {
        async handleGenerateVideoPoster () {
            const saveButtonNode = document.querySelector('.k-page-view-header [aria-label="Save"]')
            if (saveButtonNode) {
                saveButtonNode.click()
                await new Promise(resolve => setTimeout(resolve, 500))
            }

            let video = this.$el.querySelector('video')

            if (!video) {
                video             = document.createElement('video')
                video.src         = this.content.url
                video.crossOrigin = 'anonymous'
                video.muted       = true
                video.playsInline = true

                await new Promise(resolve => {
                    video.addEventListener('loadedmetadata', resolve, { once: true })
                })
                
                video.currentTime = 0
                
                await new Promise(resolve => {
                    video.addEventListener('seeked', resolve, { once: true })
                })
            }
            
            const canvas        = document.createElement('canvas')
                  canvas.width  = video.videoWidth
                  canvas.height = video.videoHeight
            const ctx           = canvas.getContext('2d')
            
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

            canvas.toBlob(async blob => {
                try {
                    const posterFilename = this.id + '_poster.jpeg'

                    const formData = new FormData();
                    formData.append('pageId', this.pageId)
                    formData.append('blockId', this.blockId)
                    formData.append('fieldName', this.fieldName)
                    formData.append('videoUrl', this.content.url)
                    formData.append('posterFilename', posterFilename)
                    formData.append('posterFile', blob, posterFilename) 
                    
                    const response = await fetch('/external-video/upload', {
                        method: 'POST',
                        body: formData
                    })
                    
                    if (!response.ok) throw new Error('(External Video) Upload failed - Response Status: ' + response.status)
                
                    const data = await response.json()

                    if (data.success) {
                        this.posterUrl = data.file.url
                        this.$reload()    
                    }                
                } catch (err) {
                    console.error('(External Video) Upload failed - Error:', err)
                } finally {
                    canvas.width = 0
                    canvas.height = 0
                }
                
            }, 'image/jpeg', 0.8)
        }
    },
    mounted() {
        const video = this.$el.querySelector('video')
        if (video) {
            video.setAttribute('crossorigin', 'anonymous')
        }
    }
}
</script>

<style>
.k-block-container {
    
    .es-external-video-block {
        display: grid;
        gap: var(--spacing-3);

        @container (min-width: 600px) {
            grid-template-columns: 1fr 1fr;
        }

        @container (max-width: 599px) {
            grid-template-columns: 1fr;
        }
    }

    .es-external-video-container {
        display: contents;

        &.is-hidden {
            display: none;
        }
    }
    
    .es-external-video-poster-wrapper {
        display: flex;
        flex-direction: column;
        justify-content: start;
        gap: var(--spacing-2);
    }
    
    .k-file-preview {
        display: block;
        margin-bottom: unset;
    }
    
    .k-file-preview-details {
        display: none;
    }
    
    .k-file-preview-frame-column {
        aspect-ratio: unset;
    }
    
    .k-file-preview-frame {
        padding: 0;
        container-type: unset;
    }
    
    .k-file-preview-frame :where(img,audio,video) {
        width: 100%;
        height: auto;
    }

    .k-block-figure {
        @container (max-width: 599px) {
            display: none;
        }
    }
    
    .k-image-frame {
        aspect-ratio: unset;
        width: max-content;
        border-radius: var(--button-rounded);
    }
    
    .k-image-frame img {
        position: static;
        max-width: 240px;
    }
    
    .es-generate-video-poster-button {
        max-width: 240px;
        width: 100%;
    }

    .es-warning {
        padding: var(--spacing-3);
        background-color: var(--color-yellow-300);
        color: var(--color-yellow-950);
        border-radius: var(--button-rounded);
    }

}
</style>