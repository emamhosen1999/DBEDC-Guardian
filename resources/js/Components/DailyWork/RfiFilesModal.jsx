import React, { useState, useCallback, useRef } from 'react';
import { Dialog, Button, IconButton, Badge, Separator, Box, Flex, Text, ScrollArea } from '@radix-ui/themes';
import {
    FileIcon,
    TrashIcon,
    DownloadIcon,
    EyeOpenIcon,
    PlusIcon,
    Cross2Icon,
    ImageIcon,
    FileTextIcon,
} from "@radix-ui/react-icons";
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';

const RfiFilesModal = ({
    isOpen,
    onClose,
    dailyWork,
    onFilesUpdated,
}) => {
    const [files, setFiles] = useState([]);
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [uploadProgress, setUploadProgress] = useState(0);
    const [deletingId, setDeletingId] = useState(null);
    const [previewFile, setPreviewFile] = useState(null);
    const fileInputRef = useRef(null);

    // Fetch files when modal opens
    React.useEffect(() => {
        if (isOpen && dailyWork?.id) {
            fetchFiles();
        }
    }, [isOpen, dailyWork?.id]);

    const fetchFiles = async () => {
        if (!dailyWork?.id) return;
        
        setLoading(true);
        try {
            const response = await axios.get(route('dailyWorks.rfiFiles.index', dailyWork.id));
            setFiles(response.data.files || []);
        } catch (error) {
            console.error('Error fetching RFI files:', error.response?.data || error.message || error);
            showToast.error(error.response?.data?.error || error.response?.data?.message || 'Failed to load RFI files');
        } finally {
            setLoading(false);
        }
    };

    const handleFileSelect = async (event) => {
        const selectedFiles = Array.from(event.target.files);
        if (selectedFiles.length === 0) return;

        setUploading(true);
        setUploadProgress(0);

        const formData = new FormData();
        selectedFiles.forEach((file) => {
            formData.append('files[]', file);
        });

        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.post(
                    route('dailyWorks.rfiFiles.upload', dailyWork.id),
                    formData,
                    {
                        headers: {
                            'Content-Type': 'multipart/form-data',
                        },
                        onUploadProgress: (progressEvent) => {
                            const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                            setUploadProgress(percent);
                        },
                    }
                );

                // Check if there were any upload errors in the response
                if (response.data.errors && response.data.errors.length > 0) {
                    const errorMessages = response.data.errors.map(e => e.error || e.file).join('; ');
                    console.error('Upload errors from server:', response.data.errors);
                    reject(errorMessages);
                    return;
                }

                // Check if no files were uploaded
                if (!response.data.files || response.data.files.length === 0) {
                    console.error('No files were uploaded:', response.data);
                    reject('No files were uploaded. Please try again.');
                    return;
                }

                // Refresh file list
                await fetchFiles();
                
                // Notify parent to update counts
                if (onFilesUpdated) {
                    onFilesUpdated(response.data.total_files);
                }
                
                resolve(response.data.message || 'Files uploaded successfully');
            } catch (error) {
                console.error('Error uploading files:', error.response?.data || error.message || error);
                const errorMessage = error.response?.data?.errors?.files?.[0] 
                    || error.response?.data?.error 
                    || error.response?.data?.message
                    || 'Failed to upload files';
                reject(errorMessage);
            } finally {
                setUploading(false);
                setUploadProgress(0);
                // Reset file input
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            }
        });

        showToast.promise(promise, {
            loading: 'Uploading files...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    };

    const handleDelete = async (mediaId) => {
        if (!confirm('Are you sure you want to delete this file?')) return;

        setDeletingId(mediaId);
        
        const promise = new Promise(async (resolve, reject) => {
            try {
                await axios.delete(route('dailyWorks.rfiFiles.delete', [dailyWork.id, mediaId]));
                
                // Refresh file list
                await fetchFiles();
                
                // Notify parent to update counts
                if (onFilesUpdated) {
                    const newCount = files.length - 1;
                    onFilesUpdated(newCount);
                }
                
                resolve('File deleted successfully');
            } catch (error) {
                console.error('Error deleting file:', error.response?.data || error.message || error);
                reject(error.response?.data?.error || error.response?.data?.message || 'Failed to delete file');
            } finally {
                setDeletingId(null);
            }
        });

        showToast.promise(promise, {
            loading: 'Deleting file...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    };

    const handlePreview = (file) => {
        if (file.is_image) {
            setPreviewFile(file);
        } else {
            // Open PDF in new tab
            window.open(file.url, '_blank');
        }
    };

    const handleDownload = (file) => {
        window.open(route('dailyWorks.rfiFiles.download', [dailyWork.id, file.id]), '_blank');
    };

    const renderFileIcon = (file) => {
        if (file.is_image) {
            return <ImageIcon style={{ width: 20, height: 20, color: 'var(--blue-9)' }} />;
        }
        return <FileTextIcon style={{ width: 20, height: 20, color: 'var(--red-9)' }} />;
    };

    const renderFilePreview = (file) => {
        if (file.is_image) {
            return (
                <Box
                    style={{ position: 'relative', width: 64, height: 64, flexShrink: 0, cursor: 'pointer', borderRadius: 'var(--radius-2)', overflow: 'hidden' }}
                    onClick={() => handlePreview(file)}
                >
                    <img
                        src={file.thumb_url || file.url}
                        alt={file.name}
                        style={{ width: 64, height: 64, objectFit: 'cover', display: 'block' }}
                    />
                    <Flex
                        align="center" justify="center"
                        style={{ position: 'absolute', inset: 0, background: 'rgba(0,0,0,0.45)', borderRadius: 'var(--radius-2)' }}
                    >
                        <EyeOpenIcon style={{ width: 20, height: 20, color: 'white' }} />
                    </Flex>
                </Box>
            );
        }
        return (
            <Flex
                align="center" justify="center"
                style={{ width: 64, height: 64, flexShrink: 0, borderRadius: 'var(--radius-2)', background: 'var(--red-a3)', cursor: 'pointer' }}
                onClick={() => handlePreview(file)}
            >
                <FileTextIcon style={{ width: 32, height: 32, color: 'var(--red-9)' }} />
            </Flex>
        );
    };

    return (
        <>
            <Dialog.Root open={isOpen} onOpenChange={(v) => { if (!v) onClose(); }}>
                <Dialog.Content maxWidth="640px" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                    <Dialog.Title>
                        <Flex align="center" gap="2">
                            <FileIcon style={{ width: 20, height: 20, color: 'var(--accent-9)' }} />
                            <Text>RFI Files - {dailyWork?.number}</Text>
                        </Flex>
                    </Dialog.Title>
                    <Dialog.Description size="2" color="gray" mb="3">
                        Manage uploaded RFI documents and images
                    </Dialog.Description>

                    <Box>
                        {/* Upload Section */}
                        <Box style={{ border: '2px dashed var(--gray-a6)', borderRadius: 'var(--radius-2)', padding: '16px', textAlign: 'center' }}>
                            <input
                                ref={fileInputRef}
                                type="file"
                                multiple
                                accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                                onChange={handleFileSelect}
                                style={{ display: 'none' }}
                                id="rfi-file-input"
                            />
                            {uploading ? (
                                <Flex direction="column" gap="2" align="center" py="4">
                                    <Box style={{ width: '100%', maxWidth: 320, height: 8, background: 'var(--gray-a5)', borderRadius: 99, overflow: 'hidden' }}>
                                        <Box style={{ width: `${uploadProgress}%`, height: '100%', background: 'var(--accent-9)', transition: 'width 300ms' }} />
                                    </Box>
                                    <Text size="1" color="gray">Uploading files... {uploadProgress}%</Text>
                                </Flex>
                            ) : (
                                <label htmlFor="rfi-file-input" style={{ cursor: 'pointer', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '16px 0' }}>
                                    <Box style={{ padding: 12, borderRadius: '50%', background: 'var(--accent-a3)' }}>
                                        <PlusIcon style={{ width: 24, height: 24, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Text size="2" weight="medium" as="p">Click to upload files</Text>
                                        <Text size="1" color="gray" as="p">Images (JPEG, PNG, WebP, GIF) or PDF files up to 10MB each</Text>
                                    </Box>
                                </label>
                            )}
                        </Box>

                        <Separator size="4" my="3" />

                        {/* Files List */}
                        {loading ? (
                            <Flex justify="center" py="8">
                                <Box style={{ width: 32, height: 32, border: '3px solid var(--accent-a6)', borderTop: '3px solid var(--accent-9)', borderRadius: '50%', animation: 'spin 0.75s linear infinite' }} />
                            </Flex>
                        ) : files.length === 0 ? (
                            <Flex direction="column" align="center" py="8" gap="2">
                                <FileIcon style={{ width: 48, height: 48, opacity: 0.5, color: 'var(--gray-9)' }} />
                                <Text size="2" color="gray">No files uploaded yet</Text>
                            </Flex>
                        ) : (
                            <ScrollArea style={{ maxHeight: 360 }}>
                                <Flex direction="column" gap="2" pr="2">
                                    {files.map((file) => (
                                        <Flex
                                            key={file.id}
                                            align="center" gap="3"
                                            style={{ padding: 12, borderRadius: 'var(--radius-2)', background: 'var(--gray-a2)' }}
                                        >
                                            {renderFilePreview(file)}
                                            <Box style={{ flex: 1, minWidth: 0 }}>
                                                <Text size="2" weight="medium" as="p" title={file.name} style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>
                                                    {file.name}
                                                </Text>
                                                <Flex align="center" gap="2" mt="1">
                                                    <Badge color={file.is_image ? 'indigo' : 'red'} variant="soft" size="1">
                                                        {file.is_image ? 'Image' : 'PDF'}
                                                    </Badge>
                                                    <Text size="1" color="gray">{file.human_size}</Text>
                                                </Flex>
                                            </Box>
                                            <Flex align="center" gap="1">
                                                <IconButton size="1" variant="ghost" color="gray" onClick={() => handlePreview(file)} title="Preview">
                                                    <EyeOpenIcon style={{ width: 16, height: 16 }} />
                                                </IconButton>
                                                <IconButton size="1" variant="ghost" color="gray" onClick={() => handleDownload(file)} title="Download">
                                                    <DownloadIcon style={{ width: 16, height: 16 }} />
                                                </IconButton>
                                                <IconButton size="1" variant="ghost" color="red" loading={deletingId === file.id} onClick={() => handleDelete(file.id)} title="Delete">
                                                    {deletingId !== file.id && <TrashIcon style={{ width: 16, height: 16 }} />}
                                                </IconButton>
                                            </Flex>
                                        </Flex>
                                    ))}
                                </Flex>
                            </ScrollArea>
                        )}
                    </Box>

                    <Flex justify="between" align="center" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                        <Text size="2" color="gray">{files.length} file{files.length !== 1 ? 's' : ''} uploaded</Text>
                        <Button color="indigo" variant="ghost" onClick={onClose}>Close</Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Image Preview Modal */}
            {previewFile && (
                <Dialog.Root open={!!previewFile} onOpenChange={(v) => { if (!v) setPreviewFile(null); }}>
                    <Dialog.Content maxWidth="900px" style={{ padding: 0 }}>
                        <Flex align="center" justify="between" px="4" py="3" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                            <Text size="2" weight="medium" style={{ maxWidth: '80%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{previewFile.name}</Text>
                            <IconButton size="1" variant="ghost" color="gray" onClick={() => setPreviewFile(null)}>
                                <Cross2Icon style={{ width: 20, height: 20 }} />
                            </IconButton>
                        </Flex>
                        <Box>
                            <img
                                src={previewFile.url}
                                alt={previewFile.name}
                                style={{ width: '100%', height: 'auto', objectFit: 'contain', maxHeight: '70vh', display: 'block' }}
                            />
                        </Box>
                    </Dialog.Content>
                </Dialog.Root>
            )}
        </>
    );
};

export default RfiFilesModal;
