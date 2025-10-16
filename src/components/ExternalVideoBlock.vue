<template>
    <div class="es-external-video-block">
        <k-video-file-preview
            :details="details"
            :url="content.url"
            class="k-file-preview"
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
                    :src="content.poster[0]?.url"
                />
            </k-block-figure>
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
        }
    },
    methods: {
        handleUrlInput (url) {
            this.update({
                url
            })
        },
        async handleGenerateVideoPoster () {
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
                    const formatCurrentTime = (time) => {
                        const seconds = Math.floor(time)
                        const hundredths = Math.round((time % 1) * 100)
                        const hundredthsPadded = hundredths.toString().padStart(2, '0')
                        return `${seconds}_${hundredthsPadded}`
                    }
                    
                    const posterFilename = this.id + '_' + formatCurrentTime(video.currentTime) + Date.now() + '.png'
                    
                    const formData = new FormData();
                    formData.append('pageId', this.pageId)
                    formData.append('blockId', this.blockId)
                    formData.append('fieldName', this.fieldName)
                    formData.append('posterFilename', posterFilename)
                    formData.append('posterFile', blob, posterFilename) 
                    
                    const response = await fetch('/external-video/upload', {
                        method: 'POST',
                        body: formData
                    })
                    
                    if (!response.ok) throw new Error('(External Video) Upload failed - Response Status: ' + response.status)
                    
                    const data = await response.json()
                    
                    if (data.success) this.$reload()
                
                } catch (err) {
                    console.error('(External Video) Upload failed - Error:', err)
                } finally {
                    canvas.width = 0
                    canvas.height = 0
                }
                
            }, 'image/png')
        }
    },
    mounted() {
        const video = this.$el.querySelector('video')
        if (video) {
            video.setAttribute('crossorigin', 'anonymous')
        }
    },
}
</script>

<style>
.k-block-container {
    
    .es-external-video-block {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--spacing-3);
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
}
</style>