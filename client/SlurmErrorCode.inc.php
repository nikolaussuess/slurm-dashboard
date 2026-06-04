<?php

namespace client;

/**
 * Slurm error codes (slurm_err_t) that are relevant for interpreting slurmrestd
 * error responses. Values match the C enum in slurm/slurm_errno.h.
 * @see https://github.com/SchedMD/slurm/blob/master/slurm/slurm_errno.h
 */
enum SlurmErrorCode: int
{
    /** Generic unspecified Slurm error */
    case SLURM_ERROR = -1;

    /** slurmctld could not be contacted (connect failure) */
    case SLURMCTLD_COMMUNICATIONS_CONNECTION_ERROR = 1800;
    /** slurmctld could not be contacted (send failure) */
    case SLURMCTLD_COMMUNICATIONS_SEND_ERROR = 1801;
    /** slurmctld could not be contacted (receive failure) */
    case SLURMCTLD_COMMUNICATIONS_RECEIVE_ERROR = 1802;
    /** slurmctld could not be contacted (shutdown failure) */
    case SLURMCTLD_COMMUNICATIONS_SHUTDOWN_ERROR = 1803;
    /** slurmctld could not be contacted (backoff) */
    case SLURMCTLD_COMMUNICATIONS_BACKOFF = 1804;
    /** slurmctld could not be contacted (hard drop) */
    case SLURMCTLD_COMMUNICATIONS_HARD_DROP = 1805;

    /** Access or permission denied */
    case ESLURM_ACCESS_DENIED = 2002;

    /** Job ID does not exist in the active queue */
    case ESLURM_INVALID_JOB_ID = 2017;

    /** Cannot open a connection to slurmdbd */
    case ESLURM_DB_CONNECTION = 7000;
    /** slurmdbd connection is invalid (e.g. auth failure against database) */
    case ESLURM_DB_CONNECTION_INVALID = 7008;

    /** REST endpoint returned an empty result set */
    case ESLURM_REST_EMPTY_RESULT = 9003;
}
